<?php

namespace anvildev\beacon\tests\unit\Tracking;

use anvildev\beacon\models\TrackingScript;
use PHPUnit\Framework\TestCase;

final class TrackingScriptTest extends TestCase
{
    public function testHoldsCoreAttributes(): void
    {
        $script = new TrackingScript([
            'uid' => 'uid-1',
            'name' => 'GA4 Production',
            'provider' => 'ga4',
            'config' => ['measurementId' => 'G-XXXXX'],
            'placement' => 'head',
            'sortOrder' => 0,
            'siteOverrides' => null,
        ]);

        $this->assertSame('uid-1', $script->uid);
        $this->assertSame('ga4', $script->provider);
        $this->assertSame(['measurementId' => 'G-XXXXX'], $script->config);
        $this->assertSame('head', $script->placement);
    }

    public function testValidatesProviderAgainstAllowList(): void
    {
        $script = new TrackingScript(['provider' => 'invalid']);
        $this->assertFalse($script->validate(['provider']));
    }

    public function testAcceptsBuiltInProviderHandle(): void
    {
        // Enum-fallback path (plugin not booted in a pure unit test): built-in
        // handles validate. Custom EVENT-registered handles are covered by the
        // integration/smoke tests where the registry is populated.
        $script = new TrackingScript([
            'name' => 'GA4',
            'provider' => 'ga4',
            'placement' => 'head',
            'config' => ['measurementId' => 'G-XXXXX'],
        ]);
        $this->assertTrue($script->validate(['provider']));
    }

    public function testValidatesPlacementAgainstAllowList(): void
    {
        $script = new TrackingScript(['placement' => 'sidebar']);
        $this->assertFalse($script->validate(['placement']));
    }

    public function testRejectsNameLongerThan255Chars(): void
    {
        $script = new TrackingScript(['name' => str_repeat('a', 256)]);
        $this->assertFalse($script->validate(['name']));
    }

    public function testRejectsNegativeSortOrder(): void
    {
        $script = new TrackingScript(['sortOrder' => -1]);
        $this->assertFalse($script->validate(['sortOrder']));
    }

    public function testAcceptsWellFormedSiteOverrides(): void
    {
        $script = new TrackingScript([
            'name' => 'GA4',
            'provider' => 'ga4',
            'placement' => 'head',
            'config' => ['measurementId' => 'G-XXXXX'],
            'siteOverrides' => [
                'site-uid-1' => ['enabled' => false],
                'site-uid-2' => ['config' => ['measurementId' => 'G-YYYYY']],
                'site-uid-3' => ['enabled' => true, 'config' => ['measurementId' => 'G-ZZZZZ']],
            ],
        ]);
        $this->assertTrue($script->validate(['siteOverrides']));
    }

    public function testRejectsSiteOverridesWithExtraKeys(): void
    {
        $script = new TrackingScript([
            'siteOverrides' => [
                'site-uid-1' => ['enabled' => true, 'unexpected' => 'nope'],
            ],
        ]);
        $this->assertFalse($script->validate(['siteOverrides']));
    }
}
