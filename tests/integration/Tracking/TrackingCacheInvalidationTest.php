<?php

namespace anvildev\beacon\tests\integration\Tracking;

use anvildev\beacon\Plugin;
use anvildev\beacon\records\TrackingScriptRecord;
use Craft;
use craft\test\TestCase;

final class TrackingCacheInvalidationTest extends TestCase
{
    public function testSavingScriptInvalidatesCache(): void
    {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $script = new TrackingScriptRecord();
        $script->name = 'GA4';
        $script->provider = 'ga4';
        $script->config = ['measurementId' => 'G-FIRST'];
        $script->placement = 'head';
        $script->save();

        $first = Plugin::getInstance()->tracking->renderPlacement($siteId, 'head');
        $this->assertStringContainsString('G-FIRST', $first);

        $script->config = ['measurementId' => 'G-SECOND'];
        $script->save();

        $second = Plugin::getInstance()->tracking->renderPlacement($siteId, 'head');
        $this->assertStringContainsString('G-SECOND', $second);
        $this->assertStringNotContainsString('G-FIRST', $second);

        $script->delete();
    }
}
