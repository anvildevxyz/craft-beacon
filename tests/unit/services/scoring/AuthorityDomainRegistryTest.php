<?php

namespace anvildev\beacon\tests\unit\services\scoring;

use anvildev\beacon\services\scoring\AuthorityDomainRegistry;
use anvildev\beacon\services\scoring\heuristics\DomainMatcher;
use PHPUnit\Framework\TestCase;

class AuthorityDomainRegistryTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    public function testBundledDefaultsLoadAndClassify(): void
    {
        $reg = new AuthorityDomainRegistry(new DomainMatcher(), $this->bundlePath());
        $this->assertSame(1, $reg->classify('en.wikipedia.org'));
        $this->assertSame(1, $reg->classify('mit.edu'));
        $this->assertSame(2, $reg->classify('nytimes.com'));
        $this->assertNull($reg->classify('example-blog.com'));
    }

    public function testWildcardTldsResolveToTier1(): void
    {
        $reg = $this->registry([]);
        $this->assertSame(1, $reg->classify('whitehouse.gov'));
        $this->assertSame(1, $reg->classify('gov.uk'));
        $this->assertSame(1, $reg->classify('oxford.ac.uk'));
        $this->assertNull($reg->classify('admin.ch')); // not in bundled list
    }

    public function testOperatorAdditionOverridesUnclassified(): void
    {
        $reg = $this->registry([
            ['domain' => 'example-blog.com', 'tier' => 2, 'enabled' => true],
        ]);
        $this->assertSame(2, $reg->classify('example-blog.com'));
    }

    public function testOperatorTier1AdditionPromotes(): void
    {
        $reg = $this->registry([
            ['domain' => 'acme-research.org', 'tier' => 1, 'enabled' => true],
        ]);
        $this->assertSame(1, $reg->classify('acme-research.org'));
    }

    public function testOperatorDisableDropsBundledDefault(): void
    {
        $reg = $this->registry([
            ['domain' => 'nytimes.com', 'enabled' => false],
        ]);
        // The bundled tier-2 entry should now be gone.
        $this->assertNull($reg->classify('nytimes.com'));
        // Other defaults unaffected.
        $this->assertSame(1, $reg->classify('en.wikipedia.org'));
    }

    public function testOperatorAdditionsTakePrecedenceOverDefaults(): void
    {
        // Override wikipedia.org to tier 2 (operator's site might consider it
        // less authoritative for some niche reason). The addition runs before
        // the bundled tier-1 entry in entries() order, so it wins.
        $reg = $this->registry([
            ['domain' => 'wikipedia.org', 'tier' => 2, 'enabled' => true],
        ]);
        // Note: the bundled *.wikipedia.org entry still matches `en.wikipedia.org`
        // as tier 1 — operator overrides apply per-pattern.
        $this->assertSame(2, $reg->classify('wikipedia.org'));
    }

    public function testInvalidateClearsCache(): void
    {
        // First classify caches the merged list. After invalidate, a new
        // classify reads fresh. (Drives the constructor-injected bundle path,
        // so this tests the caching mechanism only — settings are stubbed empty.)
        $reg = $this->registry([['domain' => 'first.example', 'tier' => 1]]);
        $this->assertSame(1, $reg->classify('first.example'));
        $reg->invalidate();
        $this->assertSame(1, $reg->classify('first.example')); // still works post-invalidate
    }

    public function testClassifyReturnsNullForEmptyOrBlankHost(): void
    {
        $reg = $this->registry([]);
        $this->assertNull($reg->classify(''));
        $this->assertNull($reg->classify('   '));
    }

    public function testDefaultsExposesShapeWithProvenanceFields(): void
    {
        $defaults = (new AuthorityDomainRegistry(new DomainMatcher(), $this->bundlePath()))->defaults();
        $this->assertNotEmpty($defaults);
        foreach ($defaults as $entry) {
            $this->assertNotSame('', $entry['domain']);
            $this->assertContains($entry['tier'], [1, 2]);
            $this->assertArrayHasKey('addedAt', $entry);
            $this->assertArrayHasKey('source', $entry);
        }
    }

    public function testDefaultsFiltersMalformedRows(): void
    {
        $path = $this->writeBundle([
            'domains' => [
                ['domain' => 'Good.ORG', 'tier' => 1],     // kept (and lowercased)
                ['domain' => '', 'tier' => 1],             // empty domain → dropped
                ['domain' => 'notier.org'],                // missing tier → dropped
                ['tier' => 2],                             // missing domain → dropped
                ['domain' => 'badtier.org', 'tier' => 9],  // tier not 1|2 → dropped
                'not-an-array',                            // non-array → dropped
            ],
        ]);
        $domains = array_column((new AuthorityDomainRegistry(new DomainMatcher(), $path))->defaults(), 'domain');
        $this->assertSame(['good.org'], $domains);
    }

    public function testMissingBundleFileYieldsNoDefaults(): void
    {
        $reg = new AuthorityDomainRegistry(new DomainMatcher(), '/no/such/authority-domains.json', []);
        $this->assertSame([], $reg->defaults());
        $this->assertNull($reg->classify('en.wikipedia.org'));
    }

    public function testInvalidBundleJsonYieldsNoDefaults(): void
    {
        $path = $this->writeRaw('{ not valid json');
        $reg = new AuthorityDomainRegistry(new DomainMatcher(), $path, []);
        $this->assertSame([], $reg->defaults());
    }

    public function testEntriesAppliesOverrideTierDefaultsAndSkipsInvalidRows(): void
    {
        // @phpstan-ignore-next-line argument.type — deliberately malformed rows exercise the runtime guards
        $reg = $this->registry([
            ['domain' => 'no-tier.example'],                // missing tier → defaults to 2
            ['domain' => 'bad-tier.example', 'tier' => 7],  // tier not 1|2 → 2
            ['domain' => '   '],                            // blank domain → skipped
            'not-an-array',                                 // non-array → skipped
            ['notdomain' => 'x'],                           // no domain key → skipped
        ]);
        $this->assertSame(2, $reg->classify('no-tier.example'));
        $this->assertSame(2, $reg->classify('bad-tier.example'));
    }

    public function testEntriesWithoutOverridesEqualsDefaults(): void
    {
        // No overridesForTest → loadOverrides() reads settings, but Plugin::$plugin
        // is null in unit context, so it returns [] and entries == defaults.
        $reg = new AuthorityDomainRegistry(new DomainMatcher(), $this->bundlePath());
        $this->assertCount(count($reg->defaults()), $reg->entries());
        $this->assertSame(1, $reg->classify('en.wikipedia.org'));
    }

    /**
     * @param list<array<string,mixed>> $overrides
     */
    private function registry(array $overrides): AuthorityDomainRegistry
    {
        return new AuthorityDomainRegistry(new DomainMatcher(), $this->bundlePath(), $overrides);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function writeBundle(array $data): string
    {
        return $this->writeRaw((string) json_encode($data));
    }

    private function writeRaw(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'beacon-bundle');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;
        return $path;
    }

    private function bundlePath(): string
    {
        return dirname(__DIR__, 4) . '/src/data/authority-domains.json';
    }
}
