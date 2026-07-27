<?php

namespace anvildev\beacon\tests\unit\helpers\links;

use anvildev\beacon\helpers\links\UriNormalizer;
use PHPUnit\Framework\TestCase;

class UriNormalizerTest extends TestCase
{
    public function testStripsLeadingSlash(): void
    {
        $this->assertSame('about', UriNormalizer::normalize('/about'));
    }

    public function testStripsTrailingSlash(): void
    {
        $this->assertSame('about', UriNormalizer::normalize('about/'));
    }

    public function testStripsQueryString(): void
    {
        $this->assertSame('about', UriNormalizer::normalize('/about?ref=foo'));
    }

    public function testLowercases(): void
    {
        $this->assertSame('about/team', UriNormalizer::normalize('/About/Team'));
    }

    public function testHandlesRootAsEmptyString(): void
    {
        $this->assertSame('', UriNormalizer::normalize('/'));
    }

    public function testHandlesEmptyString(): void
    {
        $this->assertSame('', UriNormalizer::normalize(''));
    }

    public function testCollapsesMultipleSlashes(): void
    {
        $this->assertSame('about/team', UriNormalizer::normalize('/about//team'));
    }

    public function testPreservesUnicodeSegments(): void
    {
        $this->assertSame('über-uns', UriNormalizer::normalize('/über-uns'));
    }
}
