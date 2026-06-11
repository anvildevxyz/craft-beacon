<?php

namespace anvildev\beacon\tests\integration\Tracking;

use anvildev\beacon\records\TrackingScriptRecord;
use Craft;
use craft\test\TestCase;

final class TrackingProjectConfigTest extends TestCase
{
    public function testApplyingProjectConfigCreatesScript(): void
    {
        $uid = 'tc-uid-' . bin2hex(random_bytes(4));

        try {
            Craft::$app->getProjectConfig()->set("beacon.trackingScripts.{$uid}", [
                'name' => 'GA4',
                'provider' => 'ga4',
                'config' => ['measurementId' => 'G-PC'],
                'placement' => 'head',
                'sortOrder' => 0,
                'siteOverrides' => null,
            ]);

            /** @var TrackingScriptRecord|null $row */
            $row = TrackingScriptRecord::find()->where(['uid' => $uid])->one();
            $this->assertNotNull($row);
            $this->assertSame('ga4', $row->provider);
        } finally {
            Craft::$app->getProjectConfig()->remove("beacon.trackingScripts.{$uid}");
        }
    }

    public function testRemovingFromProjectConfigDeletesScript(): void
    {
        $uid = 'tc-uid-' . bin2hex(random_bytes(4));
        Craft::$app->getProjectConfig()->set("beacon.trackingScripts.{$uid}", [
            'name' => 'GA4',
            'provider' => 'ga4',
            'config' => ['measurementId' => 'G-PC'],
            'placement' => 'head',
            'sortOrder' => 0,
            'siteOverrides' => null,
        ]);
        $this->assertNotNull(TrackingScriptRecord::find()->where(['uid' => $uid])->one());

        Craft::$app->getProjectConfig()->remove("beacon.trackingScripts.{$uid}");
        $this->assertNull(TrackingScriptRecord::find()->where(['uid' => $uid])->one());
    }
}
