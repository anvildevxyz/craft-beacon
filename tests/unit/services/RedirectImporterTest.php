<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\enums\RedirectQueryStringMode;
use anvildev\beacon\enums\RedirectType;
use anvildev\beacon\helpers\RedirectTargets;
use anvildev\beacon\services\RedirectImporter;
use PHPUnit\Framework\TestCase;
use ReflectionObject;

class RedirectImporterTest extends TestCase
{
    public function testParseValidRows(): void
    {
        $importer = new RedirectImporter();
        $csv = "source,target,statusCode\n/old,/new,301\n/foo,/bar,302\n";
        $rows = $this->invokePrivate($importer, 'parseCsv', [$csv]);

        $this->assertCount(2, $rows['valid']);
        $this->assertSame([], $rows['errors']);
        $this->assertSame('/old', $rows['valid'][0]['sourceUri']);
        $this->assertSame(RedirectType::Exact, $rows['valid'][0]['type']);
    }

    /**
     * The target/destination allowlist is the single gate that keeps a stored
     * redirect or short-link from becoming an open-redirect / phishing vector
     * when emitted as a `Location:` header. ShortLinkElement::validateDestination
     * and RedirectElement::validateTargetUri both delegate here.
     *
     * @dataProvider unsafeTargets
     */
    public function testRejectsUnsafeTargets(string $target): void
    {
        $this->assertNotNull(
            RedirectTargets::validateTargetUri($target),
            "Expected '$target' to be rejected",
        );
    }

    /** @return array<string, array{0:string}> */
    public static function unsafeTargets(): array
    {
        return [
            'protocol-relative' => ['//evil.example'],
            'protocol-relative with path' => ['//evil.example/login'],
            'javascript scheme' => ['javascript:alert(1)'],
            'data scheme' => ['data:text/html,<script>alert(1)</script>'],
            'vbscript scheme' => ['vbscript:msgbox(1)'],
            'file scheme' => ['file:///etc/passwd'],
            'bare scheme-ish' => ['ftp://evil.example'],
            'no scheme no slash' => ['evil.example'],
        ];
    }

    /**
     * @dataProvider safeTargets
     */
    public function testAcceptsSafeTargets(string $target): void
    {
        $this->assertNull(
            RedirectTargets::validateTargetUri($target),
            "Expected '$target' to be accepted",
        );
    }

    /** @return array<string, array{0:string}> */
    public static function safeTargets(): array
    {
        return [
            'root-relative path' => ['/landing'],
            'nested relative path' => ['/sale/black-friday'],
            'https absolute' => ['https://example.com/page'],
            'http absolute' => ['http://example.com/'],
        ];
    }

    public function testStripsLeadingUtf8Bom(): void
    {
        $importer = new RedirectImporter();
        // Excel/Numbers/Windows tools prepend a UTF-8 BOM to the first cell;
        // without stripping it the `source` header never matches.
        $csv = "\xEF\xBB\xBFsource,target,statusCode\n/old,/new,301\n";
        $rows = $this->invokePrivate($importer, 'parseCsv', [$csv]);

        $this->assertSame([], $rows['errors']);
        $this->assertCount(1, $rows['valid']);
        $this->assertSame('/old', $rows['valid'][0]['sourceUri']);
    }

    public function testStripsOnlyOneLeadingBom(): void
    {
        $importer = new RedirectImporter();
        // A second BOM is real content and must remain on the header cell so
        // the (now malformed) `source` column is not silently matched.
        $csv = "\xEF\xBB\xBF\xEF\xBB\xBFsource,target,statusCode\n/old,/new,301\n";
        $rows = $this->invokePrivate($importer, 'parseCsv', [$csv]);

        $this->assertCount(0, $rows['valid']);
        $this->assertCount(1, $rows['errors']);
        $this->assertStringContainsString('source', $rows['errors'][0]['reason']);
    }

    public function testRejectsMissingSourceOrTarget(): void
    {
        $importer = new RedirectImporter();
        $csv = "source,target,statusCode\n,/new,301\n/foo,,301\n";
        $rows = $this->invokePrivate($importer, 'parseCsv', [$csv]);

        $this->assertCount(0, $rows['valid']);
        $this->assertCount(2, $rows['errors']);
    }

    public function testRejectsInvalidStatusCode(): void
    {
        $importer = new RedirectImporter();
        $csv = "source,target,statusCode\n/old,/new,500\n";
        $rows = $this->invokePrivate($importer, 'parseCsv', [$csv]);

        $this->assertCount(0, $rows['valid']);
        $this->assertCount(1, $rows['errors']);
        $this->assertStringContainsString('statusCode', $rows['errors'][0]['reason']);
    }

    public function testStatusCodeDefaultsTo301WhenColumnMissing(): void
    {
        $importer = new RedirectImporter();
        $csv = "source,target\n/old,/new\n";
        $rows = $this->invokePrivate($importer, 'parseCsv', [$csv]);

        $this->assertCount(1, $rows['valid']);
        $this->assertSame(301, $rows['valid'][0]['statusCode']);
    }

    public function testDetectsGlobType(): void
    {
        $importer = new RedirectImporter();
        $csv = "source,target,statusCode\n/blog/*,/news/\$1,301\n";
        $rows = $this->invokePrivate($importer, 'parseCsv', [$csv]);

        $this->assertCount(1, $rows['valid']);
        $this->assertSame(RedirectType::Glob, $rows['valid'][0]['type']);
    }

    public function testDetectsRegexPrefix(): void
    {
        $importer = new RedirectImporter();
        $csv = "source,target,statusCode\nregex:^/old/(\\d+)$,/new/\$1,301\n";
        $rows = $this->invokePrivate($importer, 'parseCsv', [$csv]);

        $this->assertCount(1, $rows['valid']);
        $this->assertSame(RedirectType::Regex, $rows['valid'][0]['type']);
        $this->assertSame('^/old/(\d+)$', $rows['valid'][0]['sourceUri']);
    }

    public function testRejectsMalformedRegex(): void
    {
        $importer = new RedirectImporter();
        $csv = "source,target,statusCode\nregex:(unclosed,/new,301\n";
        $rows = $this->invokePrivate($importer, 'parseCsv', [$csv]);

        $this->assertCount(0, $rows['valid']);
        $this->assertCount(1, $rows['errors']);
    }

    public function testExportToCsvHeader(): void
    {
        $importer = new RedirectImporter();
        $csv = $importer->exportToCsv([]);
        $this->assertSame("source,target,statusCode,queryStringMode\n", $csv);
    }

    public function testExportToCsvRoundTripsExactGlobAndRegex(): void
    {
        $importer = new RedirectImporter();
        $records = [
            $this->record('/old', '/new', 301, RedirectType::Exact),
            $this->record('/blog/*', '/news/$1', 302, RedirectType::Glob),
            $this->record('^/legacy/(\d+)$', '/v2/$1', 301, RedirectType::Regex),
        ];

        // @phpstan-ignore-next-line argument.type — $records are DB-free duck-typed record fakes
        $csv = $importer->exportToCsv($records);
        $parsed = $this->invokePrivate($importer, 'parseCsv', [$csv]);

        $this->assertSame([], $parsed['errors']);
        $this->assertCount(3, $parsed['valid']);
        $this->assertSame(RedirectType::Exact, $parsed['valid'][0]['type']);
        $this->assertSame('/old', $parsed['valid'][0]['sourceUri']);
        $this->assertSame(RedirectType::Glob, $parsed['valid'][1]['type']);
        $this->assertSame('/blog/*', $parsed['valid'][1]['sourceUri']);
        $this->assertSame(RedirectType::Regex, $parsed['valid'][2]['type']);
        $this->assertSame('^/legacy/(\d+)$', $parsed['valid'][2]['sourceUri']);
    }

    public function testQueryStringModeRoundTrips(): void
    {
        $importer = new RedirectImporter();
        $records = [
            $this->record('/a', '/x', 301, RedirectType::Exact, RedirectQueryStringMode::Preserve),
            $this->record('/b', '/y', 301, RedirectType::Exact, RedirectQueryStringMode::Match),
        ];

        // @phpstan-ignore-next-line argument.type — $records are DB-free duck-typed record fakes
        $csv = $importer->exportToCsv($records);
        $parsed = $this->invokePrivate($importer, 'parseCsv', [$csv]);

        $this->assertSame([], $parsed['errors']);
        $this->assertSame(RedirectQueryStringMode::Preserve, $parsed['valid'][0]['queryStringMode']);
        $this->assertSame(RedirectQueryStringMode::Match, $parsed['valid'][1]['queryStringMode']);
    }

    public function testQueryStringModeDefaultsToIgnoreWhenColumnMissing(): void
    {
        $importer = new RedirectImporter();
        $csv = "source,target,statusCode\n/old,/new,301\n";
        $rows = $this->invokePrivate($importer, 'parseCsv', [$csv]);

        $this->assertCount(1, $rows['valid']);
        $this->assertSame(RedirectQueryStringMode::Ignore, $rows['valid'][0]['queryStringMode']);
    }

    public function testRejectsInvalidQueryStringMode(): void
    {
        $importer = new RedirectImporter();
        $csv = "source,target,statusCode,queryStringMode\n/old,/new,301,bogus\n";
        $rows = $this->invokePrivate($importer, 'parseCsv', [$csv]);

        $this->assertCount(0, $rows['valid']);
        $this->assertCount(1, $rows['errors']);
        $this->assertStringContainsString('queryStringMode', $rows['errors'][0]['reason']);
    }

    /**
     * @dataProvider formulaInjectionTargets
     */
    public function testExportNeutralisesSpreadsheetFormulaCells(string $target, string $expectedCell): void
    {
        $importer = new RedirectImporter();
        $records = [
            $this->record($target, '/safe', 301, RedirectType::Exact),
        ];

        // @phpstan-ignore-next-line argument.type — duck-typed record fake
        $csv = $importer->exportToCsv($records);
        $lines = explode("\n", trim($csv));

        $this->assertCount(2, $lines);
        $this->assertStringContainsString($expectedCell, $lines[1]);
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function formulaInjectionTargets(): array
    {
        return [
            'equals' => ['=1+1', "'=1+1"],
            'plus' => ['+cmd', "'+cmd"],
            'minus' => ['-2+3', "'-2+3"],
            'at' => ['@SUM(A1)', "'@SUM(A1)"],
            'tab' => ["\tsecret", "'\tsecret"],
            'carriage-return' => ["\rsecret", "'\rsecret"],
            'normal-path' => ['/landing', '/landing'],
            'https-url' => ['https://example.com/x', 'https://example.com/x'],
        ];
    }

    private function record(
        string $source,
        string $target,
        int $statusCode,
        RedirectType $type,
        RedirectQueryStringMode $qsMode = RedirectQueryStringMode::Ignore,
    ): object {
        return new class($source, $target, $statusCode, $type->value, $qsMode->value) {
            public function __construct(
                public string $sourceUri,
                public string $targetUri,
                public int $statusCode,
                public string $type,
                public string $queryStringMode,
            ) {
            }
        };
    }

    /** @param array<int,mixed> $args */
    private function invokePrivate(object $obj, string $method, array $args): mixed
    {
        $ref = new ReflectionObject($obj);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($obj, $args);
    }
}
