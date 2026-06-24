<?php

namespace anvildev\beacon\tests\unit\helpers;

use anvildev\beacon\helpers\AiUsagePolicy;
use PHPUnit\Framework\TestCase;

class AiUsagePolicyTest extends TestCase
{
    public function testNormalizeCoercesUnknownToAllow(): void
    {
        $this->assertSame('allow', AiUsagePolicy::normalize(null));
        $this->assertSame('allow', AiUsagePolicy::normalize(''));
        $this->assertSame('allow', AiUsagePolicy::normalize('bogus'));
        $this->assertSame('allow', AiUsagePolicy::normalize('inherit'));
        $this->assertSame('no-train', AiUsagePolicy::normalize('NO-TRAIN'));
    }

    public function testNormalizeOrInheritReturnsNullWhenDeferring(): void
    {
        $this->assertNull(AiUsagePolicy::normalizeOrInherit(''));
        $this->assertNull(AiUsagePolicy::normalizeOrInherit('inherit'));
        $this->assertNull(AiUsagePolicy::normalizeOrInherit('bogus'));
        $this->assertSame('no-ai', AiUsagePolicy::normalizeOrInherit('no-ai'));
    }

    public function testIsRestrictive(): void
    {
        $this->assertFalse(AiUsagePolicy::isRestrictive('allow'));
        $this->assertTrue(AiUsagePolicy::isRestrictive('no-train'));
        $this->assertTrue(AiUsagePolicy::isRestrictive('no-generative-ai'));
        $this->assertTrue(AiUsagePolicy::isRestrictive('no-ai'));
    }

    public function testRobotsTokens(): void
    {
        $this->assertSame([], AiUsagePolicy::robotsTokens('allow'));
        $this->assertSame(['noai', 'noimageai'], AiUsagePolicy::robotsTokens('no-train'));
        $this->assertSame(['noai'], AiUsagePolicy::robotsTokens('no-generative-ai'));
        $this->assertSame(['noai', 'noimageai'], AiUsagePolicy::robotsTokens('no-ai'));
    }

    public function testContentSignalTokens(): void
    {
        $this->assertSame([], AiUsagePolicy::contentSignalTokens('allow'));
        $this->assertSame(['ai-train=no'], AiUsagePolicy::contentSignalTokens('no-train'));
        $this->assertSame(['ai-input=no'], AiUsagePolicy::contentSignalTokens('no-generative-ai'));
        $this->assertSame(['ai-train=no', 'ai-input=no'], AiUsagePolicy::contentSignalTokens('no-ai'));
    }

    public function testTdmReservation(): void
    {
        $this->assertSame(0, AiUsagePolicy::tdmReservation('allow'));
        $this->assertSame(1, AiUsagePolicy::tdmReservation('no-train'));
        $this->assertSame(1, AiUsagePolicy::tdmReservation('no-ai'));
    }

    public function testContentUsage(): void
    {
        $this->assertNull(AiUsagePolicy::contentUsage('allow'));
        $this->assertSame('ai-train=n', AiUsagePolicy::contentUsage('no-train'));
        $this->assertSame('ai-input=n', AiUsagePolicy::contentUsage('no-generative-ai'));
        $this->assertSame('ai-train=n, ai-input=n', AiUsagePolicy::contentUsage('no-ai'));
    }

    public function testStaticPrefixFromUriFormat(): void
    {
        $this->assertSame('/blog/', AiUsagePolicy::staticPrefixFromUriFormat('blog/{slug}'));
        $this->assertSame('/news/', AiUsagePolicy::staticPrefixFromUriFormat('news/{slug}'));
        $this->assertSame('/a/b/', AiUsagePolicy::staticPrefixFromUriFormat('a/b/{slug}'));
        $this->assertNull(AiUsagePolicy::staticPrefixFromUriFormat('{slug}'));
        $this->assertNull(AiUsagePolicy::staticPrefixFromUriFormat('__home__'));
        $this->assertNull(AiUsagePolicy::staticPrefixFromUriFormat(''));
        $this->assertSame('/docs/', AiUsagePolicy::staticPrefixFromUriFormat('/docs/{slug}'));
    }
}
