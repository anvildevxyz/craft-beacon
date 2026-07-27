<?php

namespace anvildev\beacon\tests\unit\variables;

use anvildev\beacon\variables\BeaconVariable;
use PHPUnit\Framework\TestCase;

/**
 * The no-op edges of pagination-aware hreflang rewriting: page 1 and an empty
 * alternate list, neither of which builds a URL.
 *
 * The cases that actually rewrite an href moved to
 * {@see \anvildev\beacon\tests\integration\BeaconVariablePaginationTest},
 * because `UrlHelper::urlWithParams()` needs a booted application as of
 * Craft 5.10.
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



    public function testEmptyAlternatesIsNoop(): void
    {
        $this->assertSame([], BeaconVariable::pageAlternates([], 'page', 5));
    }
}
