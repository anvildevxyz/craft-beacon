<?php

namespace anvildev\beacon\tests\unit\variables;

use anvildev\beacon\variables\BeaconVariable;
use PHPUnit\Framework\TestCase;

/**
 * Regression: homepage-style entries (URI '', '/', or '__home__') must not
 * produce a `<link rel="alternate" type="text/markdown">` pointing at
 * `https://example.com.md` (the Moldova TLD, not your site).
 */
class BeaconVariableHomepageMdTest extends TestCase
{
    public function testEmptyUriIsHomepage(): void
    {
        $this->assertTrue(BeaconVariable::isHomepageEntryUri(''));
    }

    public function testSlashUriIsHomepage(): void
    {
        $this->assertTrue(BeaconVariable::isHomepageEntryUri('/'));
    }

    public function testHomeTokenIsHomepage(): void
    {
        $this->assertTrue(BeaconVariable::isHomepageEntryUri('__home__'));
    }

    public function testNonEmptyUriIsNotHomepage(): void
    {
        $this->assertFalse(BeaconVariable::isHomepageEntryUri('blog/post-1'));
        $this->assertFalse(BeaconVariable::isHomepageEntryUri('docs/quickstart'));
        $this->assertFalse(BeaconVariable::isHomepageEntryUri('about'));
    }

    public function testUriOfOnlySlashesIsHomepage(): void
    {
        
        
        $this->assertTrue(BeaconVariable::isHomepageEntryUri('///'));
    }
}
