<?php

namespace anvildev\beacon\tests\unit\helpers;

use anvildev\beacon\helpers\SocialPlatforms;
use PHPUnit\Framework\TestCase;

class SocialPlatformsTest extends TestCase
{
    public function testAllReturnsCuratedListWithRequiredShape(): void
    {
        $all = SocialPlatforms::all();
        $this->assertNotEmpty($all);
        foreach ($all as $platform) {
            $this->assertArrayHasKey('key', $platform);
            $this->assertArrayHasKey('label', $platform);
            $this->assertArrayHasKey('placeholder', $platform);
            $this->assertIsArray($platform['handleHosts']);
        }
    }

    public function testKeysMatchPlatformOrder(): void
    {
        $this->assertSame(array_column(SocialPlatforms::all(), 'key'), SocialPlatforms::keys());
        $this->assertContains('twitter', SocialPlatforms::keys());
    }

    public function testParseHandleFromKnownHosts(): void
    {
        $this->assertSame('acme', SocialPlatforms::parseHandle('twitter', 'https://x.com/acme'));
        $this->assertSame('acme', SocialPlatforms::parseHandle('twitter', 'https://twitter.com/acme'));
    }

    public function testParseHandleStripsLeadingAt(): void
    {
        $this->assertSame('acme', SocialPlatforms::parseHandle('tiktok', 'https://tiktok.com/@acme'));
    }

    public function testParseHandleStripsWwwPrefix(): void
    {
        $this->assertSame('acme', SocialPlatforms::parseHandle('twitter', 'https://www.x.com/acme'));
    }

    public function testParseHandleUsesLastPathSegment(): void
    {
        $this->assertSame('repo', SocialPlatforms::parseHandle('github', 'https://github.com/org/repo'));
    }

    public function testParseHandleReturnsNullForUnknownHost(): void
    {
        $this->assertNull(SocialPlatforms::parseHandle('twitter', 'https://example.com/acme'));
    }

    public function testParseHandleReturnsNullForPlatformWithoutHandleHosts(): void
    {
        // LinkedIn declares no handleHosts — no handle is recoverable.
        $this->assertNull(SocialPlatforms::parseHandle('linkedin', 'https://linkedin.com/company/acme'));
    }

    public function testParseHandleReturnsNullForEmptyOrPathlessUrl(): void
    {
        $this->assertNull(SocialPlatforms::parseHandle('twitter', ''));
        $this->assertNull(SocialPlatforms::parseHandle('twitter', 'https://x.com'));
        $this->assertNull(SocialPlatforms::parseHandle('twitter', 'https://x.com/'));
    }

    public function testParseHandleReturnsNullForUnknownPlatformKey(): void
    {
        $this->assertNull(SocialPlatforms::parseHandle('does-not-exist', 'https://x.com/acme'));
    }
}
