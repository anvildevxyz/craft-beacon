<?php

namespace anvildev\beacon\tests\integration\Tracking;

use anvildev\beacon\helpers\Http;
use anvildev\beacon\Plugin;
use anvildev\beacon\records\TrackingScriptRecord;
use Craft;
use craft\test\TestCase;

final class RequestGuardTest extends TestCase
{
    public function testCpRequestRendersEmpty(): void
    {
        $script = new TrackingScriptRecord();
        $script->name = 'GA4';
        $script->provider = 'ga4';
        $script->config = ['measurementId' => 'G-ABC'];
        $script->placement = 'head';
        $script->save();

        
        $request = Http::request();
        $request->setIsCpRequest(true);

        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $output = Plugin::getInstance()->tracking->renderPlacement($siteId, 'head');
        $this->assertSame('', $output);

        $script->delete();
    }
}
