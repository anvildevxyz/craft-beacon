<?php

namespace anvildev\beacon\tests\unit\Tracking\Providers;

use anvildev\beacon\enums\TrackingPlacement;
use anvildev\beacon\enums\TrackingProvider;
use anvildev\beacon\tracking\providers\GTMProvider;
use PHPUnit\Framework\TestCase;

final class GTMProviderTest extends TestCase
{
    public function testFixedPlacementsAreHeadAndBodyStart(): void
    {
        $this->assertSame(
            [TrackingPlacement::Head, TrackingPlacement::BodyStart],
            (new GTMProvider())->getFixedPlacements(),
        );
    }

    public function testValidateRejectsBadContainerIds(): void
    {
        $provider = new GTMProvider();
        $this->assertArrayHasKey('containerId', $provider->validateConfig(['containerId' => '']));
        $this->assertArrayHasKey('containerId', $provider->validateConfig(['containerId' => 'GTM-']));
        $this->assertArrayHasKey('containerId', $provider->validateConfig(['containerId' => 'GA-XYZ']));
    }

    public function testValidateAcceptsValidContainerId(): void
    {
        $this->assertSame([], (new GTMProvider())->validateConfig(['containerId' => 'GTM-ABC123']));
    }

    public function testRenderHeadContainsLoaderScript(): void
    {
        $html = (new GTMProvider())->render(['containerId' => 'GTM-ABC123'], TrackingPlacement::Head);
        $this->assertStringContainsString("'GTM-ABC123'", $html);
        $this->assertStringContainsString('googletagmanager.com/gtm.js', $html);
        $this->assertStringContainsString('Google Tag Manager', $html);
    }

    public function testRenderBodyStartContainsNoscriptIframe(): void
    {
        $html = (new GTMProvider())->render(['containerId' => 'GTM-ABC123'], TrackingPlacement::BodyStart);
        $this->assertStringContainsString('<noscript>', $html);
        $this->assertStringContainsString('ns.html?id=GTM-ABC123', $html);
    }

    public function testRenderBodyEndReturnsEmpty(): void
    {
        $this->assertSame('', (new GTMProvider())->render(['containerId' => 'GTM-ABC123'], TrackingPlacement::BodyEnd));
    }

    public function testHeadOutputMatchesSnapshot(): void
    {
        $rendered = (new GTMProvider())->render(['containerId' => 'GTM-ABC123'], TrackingPlacement::Head);
        $snapshot = file_get_contents(dirname(__DIR__, 3) . '/snapshots/tracking/gtm-head.html');
        $this->assertSame($snapshot, $rendered);
    }

    public function testBodyStartOutputMatchesSnapshot(): void
    {
        $rendered = (new GTMProvider())->render(['containerId' => 'GTM-ABC123'], TrackingPlacement::BodyStart);
        $snapshot = file_get_contents(dirname(__DIR__, 3) . '/snapshots/tracking/gtm-bodyStart.html');
        $this->assertSame($snapshot, $rendered);
    }

    public function testGetHandleMatchesEnum(): void
    {
        $this->assertSame(TrackingProvider::Gtm->value, (new GTMProvider())->getHandle());
    }

    public function testGetDisplayNameIsNonEmpty(): void
    {
        $this->assertNotSame('', (new GTMProvider())->getDisplayName());
    }
}
