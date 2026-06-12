<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\services\RedirectImporter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Locks the import length limits to character counts (mb_strlen), matching the
 * BeaconRedirectSourcesField validator, so a multibyte source that the field
 * accepts isn't spuriously rejected on CSV import (and vice-versa).
 *
 * Exercises the DB-free `parseCsv()` directly via reflection — only
 * `importFromCsv()` touches the database.
 */
class RedirectImporterLengthTest extends TestCase
{
    /**
     * @return array{valid: list<array<string, mixed>>, errors: list<array{lineNumber: int, reason: string}>}
     */
    private function parse(string $csv): array
    {
        $method = new ReflectionMethod(RedirectImporter::class, 'parseCsv');
        /** @var array{valid: list<array<string, mixed>>, errors: list<array{lineNumber: int, reason: string}>} $result */
        $result = $method->invoke(new RedirectImporter(), $csv);
        return $result;
    }

    public function testSourceOf255MultibyteCharsIsAccepted(): void
    {
        // 'ä' is 2 bytes — 255 chars is 510 bytes, which byte-length (strlen)
        // would have rejected as "exceeds 255 characters".
        $source = str_repeat('ä', 255);
        $result = $this->parse("source,target\n{$source},/landing");

        $this->assertSame([], $result['errors'], 'a 255-character multibyte source must pass the length check');
        $this->assertCount(1, $result['valid']);
    }

    public function testSourceOf256MultibyteCharsIsRejected(): void
    {
        $source = str_repeat('ä', 256);
        $result = $this->parse("source,target\n{$source},/landing");

        $this->assertCount(1, $result['errors']);
        $this->assertSame('import.redirects.source.exceeds.255.characters', $result['errors'][0]['reason']);
    }

    public function testTargetOf500MultibyteCharsIsAccepted(): void
    {
        // Valid relative target of exactly 500 characters (1 slash + 499 'ä').
        $target = '/' . str_repeat('ä', 499);
        $result = $this->parse("source,target\n/from,{$target}");

        $this->assertSame([], $result['errors'], 'a 500-character multibyte target must pass the length check');
        $this->assertCount(1, $result['valid']);
    }

    public function testTargetOf501CharsIsRejected(): void
    {
        $target = '/' . str_repeat('a', 500);
        $result = $this->parse("source,target\n/from,{$target}");

        $this->assertCount(1, $result['errors']);
        $this->assertSame('import.redirects.target.exceeds.500.characters', $result['errors'][0]['reason']);
    }
}
