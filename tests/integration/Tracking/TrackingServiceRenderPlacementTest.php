<?php

namespace anvildev\beacon\tests\integration\Tracking;

use anvildev\beacon\Plugin;
use anvildev\beacon\records\TrackingScriptRecord;
use Craft;
use craft\test\TestCase;

final class TrackingServiceRenderPlacementTest extends TestCase
{
    public function testRendersOnlyScriptsForRequestedPlacement(): void
    {
        $headScript = new TrackingScriptRecord();
        $headScript->name = 'GA4';
        $headScript->provider = 'ga4';
        $headScript->config = ['measurementId' => 'G-HEAD'];
        $headScript->placement = 'head';
        $headScript->save();

        $bodyEndScript = new TrackingScriptRecord();
        $bodyEndScript->name = 'Custom';
        $bodyEndScript->provider = 'custom';
        $bodyEndScript->config = ['html' => '<script>BODYEND</script>'];
        $bodyEndScript->placement = 'bodyEnd';
        $bodyEndScript->save();

        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $head = Plugin::getInstance()->tracking->renderPlacement($siteId, 'head');
        $this->assertStringContainsString('G-HEAD', $head);
        $this->assertStringNotContainsString('BODYEND', $head);

        $bodyEnd = Plugin::getInstance()->tracking->renderPlacement($siteId, 'bodyEnd');
        $this->assertStringContainsString('BODYEND', $bodyEnd);
        $this->assertStringNotContainsString('G-HEAD', $bodyEnd);
    }
}
