<?php

namespace anvildev\beacon\tests\integration\Tracking;

use anvildev\beacon\Plugin;
use anvildev\beacon\records\TrackingScriptRecord;
use Craft;
use craft\test\TestCase;

final class TrackingServiceActiveScriptsTest extends TestCase
{
    public function testReturnsAllScriptsForSite(): void
    {
        $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;

        $script = new TrackingScriptRecord();
        $script->name = 'GA4';
        $script->provider = 'ga4';
        $script->config = ['measurementId' => 'G-ABC123'];
        $script->placement = 'head';
        $script->save();

        try {
            $active = Plugin::getInstance()->tracking->getActiveScripts($primarySiteId);
            $uids = array_column($active, 'uid');
            $this->assertContains($script->uid, $uids);
        } finally {
            $script->delete();
        }
    }

    public function testSiteOverrideDisableSkipsScript(): void
    {
        $primarySite = Craft::$app->sites->getPrimarySite();

        $script = new TrackingScriptRecord();
        $script->name = 'Disabled-on-primary';
        $script->provider = 'ga4';
        $script->config = ['measurementId' => 'G-DISABLED'];
        $script->placement = 'head';
        $script->siteOverrides = [
            $primarySite->uid => ['enabled' => false],
        ];
        $script->save();

        try {
            $active = Plugin::getInstance()->tracking->getActiveScripts($primarySite->id);
            $uids = array_column($active, 'uid');
            $this->assertNotContains($script->uid, $uids);
        } finally {
            $script->delete();
        }
    }
}
