<?php

namespace anvildev\beacon\tests\unit\helpers;

use anvildev\beacon\helpers\RobotsDirectives;
use PHPUnit\Framework\TestCase;

class RobotsDirectivesTest extends TestCase
{
    public function testNullStoredFallsBackToLegacyFour(): void
    {
        $map = RobotsDirectives::resolveEnabledMap(null);

        $this->assertTrue($map['noindex']);
        $this->assertTrue($map['nofollow']);
        $this->assertTrue($map['noarchive']);
        $this->assertTrue($map['nosnippet']);
        $this->assertFalse($map['noimageindex']);
        $this->assertFalse($map['max-snippet']);
        $this->assertFalse($map['max-image-preview']);
        $this->assertFalse($map['max-video-preview']);
        $this->assertFalse($map['unavailable_after']);
    }

    public function testStoredMapNormalizesUnknownKeys(): void
    {
        $map = RobotsDirectives::resolveEnabledMap(['noindex' => 1, 'bogus' => true]);

        $this->assertTrue($map['noindex']);
        $this->assertArrayNotHasKey('bogus', $map);
        $this->assertFalse($map['nofollow']);
    }

    public function testResolveActiveGatesByEnabledMap(): void
    {
        $tokens = RobotsDirectives::resolveActive(
            [
                'noindex' => true,
                'max-snippet' => '160',
                'max-image-preview' => 'large',
            ],
            [
                'noindex' => true,
                'max-snippet' => false,
                'max-image-preview' => true,
            ] + array_fill_keys(RobotsDirectives::keys(), false),
        );

        $this->assertSame(['noindex', 'max-image-preview:large'], $tokens);
    }

    public function testResolveActivePreservesDefinitionOrder(): void
    {
        $allEnabled = array_fill_keys(RobotsDirectives::keys(), true);
        $tokens = RobotsDirectives::resolveActive(
            ['nosnippet' => true, 'noindex' => true, 'max-snippet' => '120'],
            $allEnabled,
        );

        $this->assertSame(['noindex', 'nosnippet', 'max-snippet:120'], $tokens);
    }

    public function testFormatDirectiveRejectsBadEnumValue(): void
    {
        $this->assertNull(RobotsDirectives::formatDirective('max-image-preview', 'huge'));
        $this->assertSame('max-image-preview:large', RobotsDirectives::formatDirective('max-image-preview', 'large'));
    }

    public function testFormatDirectiveRejectsNonNumericInt(): void
    {
        $this->assertNull(RobotsDirectives::formatDirective('max-snippet', 'lots'));
        $this->assertSame('max-snippet:-1', RobotsDirectives::formatDirective('max-snippet', '-1'));
        $this->assertSame('max-snippet:0', RobotsDirectives::formatDirective('max-snippet', 0));
    }

    public function testDefaultFieldValuesCoversEveryDirective(): void
    {
        $defaults = RobotsDirectives::defaultFieldValues();
        foreach (RobotsDirectives::keys() as $key) {
            $this->assertArrayHasKey($key, $defaults);
        }
    }

    public function testEnabledDefinitionsFiltersByEnablementMap(): void
    {
        $enabled = RobotsDirectives::resolveEnabledMap(['noindex' => true, 'nofollow' => false]);
        $defs = RobotsDirectives::enabledDefinitions($enabled);
        $keys = array_column($defs, 'key');

        $this->assertContains('noindex', $keys);
        $this->assertNotContains('nofollow', $keys);
    }
}
