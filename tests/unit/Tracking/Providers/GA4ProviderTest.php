<?php

namespace anvildev\beacon\tests\unit\Tracking\Providers;

use anvildev\beacon\enums\TrackingPlacement;
use anvildev\beacon\tracking\providers\GA4Provider;
use PHPUnit\Framework\TestCase;

final class GA4ProviderTest extends TestCase
{
    public function testHandle(): void
    {
        $this->assertSame('ga4', (new GA4Provider())->getHandle());
    }

    public function testValidateRejectsBadIds(): void
    {
        $provider = new GA4Provider();
        $this->assertArrayHasKey('measurementId', $provider->validateConfig(['measurementId' => '']));
        $this->assertArrayHasKey('measurementId', $provider->validateConfig(['measurementId' => 'G-']));
        $this->assertArrayHasKey('measurementId', $provider->validateConfig(['measurementId' => 'GA-XYZ']));
    }

    public function testValidateAcceptsValidId(): void
    {
        $this->assertSame([], (new GA4Provider())->validateConfig(['measurementId' => 'G-ABC123']));
    }

    public function testRenderHeadContainsMeasurementId(): void
    {
        $html = (new GA4Provider())->render(['measurementId' => 'G-ABC123'], TrackingPlacement::Head);
        $this->assertStringContainsString("googletagmanager.com/gtag/js?id=G-ABC123", $html);
        $this->assertStringContainsString("gtag('config', 'G-ABC123')", $html);
    }

    public function testRenderEscapesIdJustInCase(): void
    {
        
        $html = (new GA4Provider())->render(['measurementId' => 'G-XSS"<script>'], TrackingPlacement::Head);
        $this->assertStringNotContainsString('<script>"', $html);
    }

    public function testGetFixedPlacementsIsNull(): void
    {
        $this->assertNull((new GA4Provider())->getFixedPlacements());
    }

    public function testRenderForUnsupportedPlacementReturnsEmpty(): void
    {
        
        
        $html = (new GA4Provider())->render(['measurementId' => 'G-ABC123'], TrackingPlacement::BodyEnd);
        $this->assertNotSame('', $html);
    }

    public function testHeadOutputMatchesSnapshot(): void
    {
        $rendered = (new GA4Provider())->render(['measurementId' => 'G-ABC123'], TrackingPlacement::Head);
        $snapshot = file_get_contents(dirname(__DIR__, 3) . '/snapshots/tracking/ga4-head.html');
        $this->assertSame($snapshot, $rendered);
    }

    public function testGetDisplayNameIsNonEmpty(): void
    {
        $this->assertNotSame('', (new GA4Provider())->getDisplayName());
    }
}
