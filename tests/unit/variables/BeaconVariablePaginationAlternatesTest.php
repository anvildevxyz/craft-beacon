<?php

namespace anvildev\beacon\tests\unit\variables;

use anvildev\beacon\variables\BeaconVariable;
use PHPUnit\Framework\TestCase;

/**
 * Regression: hreflang alternates must follow the canonical's page when
 * `setPagination({ canonicalMode: 'self' })` is in effect. Otherwise
 * canonical says page-N and each language alternate points at page-1 —
 * Google flags conflicting signals.
 */
class BeaconVariablePaginationAlternatesTest extends TestCase
{
    public function testPage1LeavesAlternatesUntouched(): void
    {
        $alternates = [
            ['hreflang' => 'en', 'href' => 'https://example.com/blog'],
            ['hreflang' => 'de', 'href' => 'https://example.de/blog'],
        ];
        $this->assertSame($alternates, BeaconVariable::pageAlternates($alternates, 'page', 1));
    }

    public function testPage2AppendsPageParam(): void
    {
        $alternates = [
            ['hreflang' => 'en', 'href' => 'https://example.com/blog'],
            ['hreflang' => 'de', 'href' => 'https://example.de/blog'],
        ];
        $rewritten = BeaconVariable::pageAlternates($alternates, 'page', 2);
        $this->assertSame('https://example.com/blog?page=2', $rewritten[0]['href']);
        $this->assertSame('https://example.de/blog?page=2', $rewritten[1]['href']);
        $this->assertSame('en', $rewritten[0]['hreflang']);
        $this->assertSame('de', $rewritten[1]['hreflang']);
    }

    public function testCustomPageParam(): void
    {
        $alternates = [
            ['hreflang' => 'en', 'href' => 'https://example.com/blog'],
        ];
        $rewritten = BeaconVariable::pageAlternates($alternates, 'p', 3);
        $this->assertSame('https://example.com/blog?p=3', $rewritten[0]['href']);
    }

    public function testEmptyAlternatesIsNoop(): void
    {
        $this->assertSame([], BeaconVariable::pageAlternates([], 'page', 5));
    }
}
