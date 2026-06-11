<?php

namespace anvildev\beacon\tests\unit\models;

use anvildev\beacon\models\AdsSettings;
use anvildev\beacon\models\HumansSettings;
use PHPUnit\Framework\TestCase;

class PassBModelsTest extends TestCase
{
    public function testHumansSettings(): void
    {
        $h = new HumansSettings(siteId: 1, enabled: true, body: "Team:\n- Alice");
        $this->assertSame(1, $h->siteId);
        $this->assertTrue($h->enabled);
        $this->assertSame("Team:\n- Alice", $h->body);
    }

    public function testHumansSettingsDefaults(): void
    {
        $h = new HumansSettings(siteId: 2);
        $this->assertFalse($h->enabled);
        $this->assertNull($h->body);
    }

    public function testAdsSettings(): void
    {
        $a = new AdsSettings(siteId: 1, enabled: true, assetId: 42, body: 'google.com, pub-123, DIRECT');
        $this->assertSame(42, $a->assetId);
        $this->assertSame('google.com, pub-123, DIRECT', $a->body);
    }

    public function testAdsSettingsDefaults(): void
    {
        $a = new AdsSettings(siteId: 1);
        $this->assertFalse($a->enabled);
        $this->assertNull($a->assetId);
        $this->assertNull($a->body);
    }
}
