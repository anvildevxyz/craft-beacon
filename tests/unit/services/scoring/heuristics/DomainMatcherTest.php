<?php

namespace anvildev\beacon\tests\unit\services\scoring\heuristics;

use anvildev\beacon\services\scoring\heuristics\DomainMatcher;
use PHPUnit\Framework\TestCase;

class DomainMatcherTest extends TestCase
{
    public function testExactMatch(): void
    {
        $m = new DomainMatcher();
        $this->assertTrue($m->matchesOne('nytimes.com', 'nytimes.com'));
        $this->assertFalse($m->matchesOne('www.nytimes.com', 'nytimes.com'));
        $this->assertFalse($m->matchesOne('nytimes.com.evil.example', 'nytimes.com'));
    }

    public function testWildcardMatchesApexAndSubdomains(): void
    {
        $m = new DomainMatcher();
        // `*.wikipedia.org` matches the bare apex AND any subdomain.
        $this->assertTrue($m->matchesOne('wikipedia.org', '*.wikipedia.org'));
        $this->assertTrue($m->matchesOne('en.wikipedia.org', '*.wikipedia.org'));
        $this->assertTrue($m->matchesOne('de.wikipedia.org', '*.wikipedia.org'));
        $this->assertTrue($m->matchesOne('secure.en.wikipedia.org', '*.wikipedia.org'));
        $this->assertFalse($m->matchesOne('wikipedia.com', '*.wikipedia.org'));
    }

    public function testTldWildcard(): void
    {
        $m = new DomainMatcher();
        $this->assertTrue($m->matchesOne('mit.edu', '*.edu'));
        $this->assertTrue($m->matchesOne('cs.mit.edu', '*.edu'));
        $this->assertFalse($m->matchesOne('mit.education.com', '*.edu'));
    }

    public function testCaseInsensitive(): void
    {
        $m = new DomainMatcher();
        $this->assertTrue($m->matchesOne('NYTimes.com', 'nytimes.com'));
        $this->assertTrue($m->matchesOne('EN.WIKIPEDIA.ORG', '*.wikipedia.org'));
    }

    public function testMatchesAnyOf(): void
    {
        $m = new DomainMatcher();
        $patterns = ['nytimes.com', '*.wikipedia.org', '*.edu'];
        $this->assertTrue($m->matches('en.wikipedia.org', $patterns));
        $this->assertTrue($m->matches('mit.edu', $patterns));
        $this->assertTrue($m->matches('nytimes.com', $patterns));
        $this->assertFalse($m->matches('example.com', $patterns));
    }

    public function testFirstMatchReturnsTheRule(): void
    {
        $m = new DomainMatcher();
        $this->assertSame('*.wikipedia.org', $m->firstMatch('de.wikipedia.org', ['nytimes.com', '*.wikipedia.org']));
        $this->assertNull($m->firstMatch('example.com', ['nytimes.com', '*.wikipedia.org']));
    }

    public function testHostFromAcceptsCommonHrefShapes(): void
    {
        $m = new DomainMatcher();
        $this->assertSame('example.com', $m->hostFrom('https://example.com/foo'));
        $this->assertSame('example.com', $m->hostFrom('http://example.com'));
        $this->assertSame('example.com', $m->hostFrom('//example.com/x'));
        $this->assertSame('', $m->hostFrom('/internal/path'));
        $this->assertSame('', $m->hostFrom('#fragment'));
        $this->assertSame('', $m->hostFrom('mailto:hi@example.com'));
        $this->assertSame('', $m->hostFrom(''));
    }

    public function testEmptyHostOrPatternIsNeverMatch(): void
    {
        $m = new DomainMatcher();
        $this->assertFalse($m->matchesOne('', 'nytimes.com'));
        $this->assertFalse($m->matchesOne('nytimes.com', ''));
    }
}
