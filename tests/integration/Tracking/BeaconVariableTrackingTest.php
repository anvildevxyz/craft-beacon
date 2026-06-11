<?php

namespace anvildev\beacon\tests\integration\Tracking;

use anvildev\beacon\records\TrackingScriptRecord;
use anvildev\beacon\variables\BeaconVariable;
use craft\test\TestCase;

final class BeaconVariableTrackingTest extends TestCase
{
    public function testBodyStartReturnsBodyStartScripts(): void
    {
        $script = new TrackingScriptRecord();
        $script->name = 'GTM';
        $script->provider = 'gtm';
        $script->config = ['containerId' => 'GTM-ABC'];
        $script->placement = 'head';
        $script->save();

        $variable = new BeaconVariable();
        $bodyStart = (string) $variable->bodyStart();
        $this->assertStringContainsString('ns.html?id=GTM-ABC', $bodyStart);

        $script->delete();
    }

    public function testBodyEndReturnsBodyEndScripts(): void
    {
        $script = new TrackingScriptRecord();
        $script->name = 'Matomo';
        $script->provider = 'matomo';
        $script->config = ['matomoUrl' => 'https://m.example.com', 'siteId' => '1'];
        $script->placement = 'bodyEnd';
        $script->save();

        $bodyEnd = (string) (new BeaconVariable())->bodyEnd();
        // The Matomo loader builds its URL via JS concatenation (u + 'matomo.js'),
        // so assert the host and the script ref that actually appear in the markup.
        $this->assertStringContainsString('m.example.com', $bodyEnd);
        $this->assertStringContainsString("'matomo.js'", $bodyEnd);

        $script->delete();
    }
}
