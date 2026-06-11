<?php

namespace anvildev\beacon\tests\unit\Tracking\Providers;

use anvildev\beacon\enums\TrackingPlacement;
use anvildev\beacon\enums\TrackingProvider;
use anvildev\beacon\tracking\providers\FacebookPixelProvider;
use PHPUnit\Framework\TestCase;

final class FacebookPixelProviderTest extends TestCase
{
    public function testFixedPlacementsAreHeadAndBodyStart(): void
    {
        $this->assertSame(
            [TrackingPlacement::Head, TrackingPlacement::BodyStart],
            (new FacebookPixelProvider())->getFixedPlacements(),
        );
    }

    public function testValidateRejectsBadIds(): void
    {
        $provider = new FacebookPixelProvider();
        $this->assertArrayHasKey('pixelId', $provider->validateConfig(['pixelId' => '']));
        $this->assertArrayHasKey('pixelId', $provider->validateConfig(['pixelId' => '123'])); 
        $this->assertArrayHasKey('pixelId', $provider->validateConfig(['pixelId' => 'ABC123456']));
    }

    public function testValidateAcceptsValidPixelId(): void
    {
        $this->assertSame([], (new FacebookPixelProvider())->validateConfig(['pixelId' => '1234567890']));
    }

    public function testRenderHeadContainsFbqInit(): void
    {
        $html = (new FacebookPixelProvider())->render(['pixelId' => '1234567890'], TrackingPlacement::Head);
        $this->assertStringContainsString("fbq('init', '1234567890')", $html);
    }

    public function testRenderBodyStartContainsNoscriptPixel(): void
    {
        $html = (new FacebookPixelProvider())->render(['pixelId' => '1234567890'], TrackingPlacement::BodyStart);
        $this->assertStringContainsString('<noscript>', $html);
        $this->assertStringContainsString('id=1234567890', $html);
    }

    public function testHeadOutputMatchesSnapshot(): void
    {
        $rendered = (new FacebookPixelProvider())->render(['pixelId' => '1234567890'], TrackingPlacement::Head);
        $snapshot = file_get_contents(dirname(__DIR__, 3) . '/snapshots/tracking/facebook_pixel-head.html');
        $this->assertSame($snapshot, $rendered);
    }

    public function testBodyStartOutputMatchesSnapshot(): void
    {
        $rendered = (new FacebookPixelProvider())->render(['pixelId' => '1234567890'], TrackingPlacement::BodyStart);
        $snapshot = file_get_contents(dirname(__DIR__, 3) . '/snapshots/tracking/facebook_pixel-bodyStart.html');
        $this->assertSame($snapshot, $rendered);
    }

    public function testGetHandleMatchesEnum(): void
    {
        $this->assertSame(TrackingProvider::FacebookPixel->value, (new FacebookPixelProvider())->getHandle());
    }

    public function testGetDisplayNameIsNonEmpty(): void
    {
        $this->assertNotSame('', (new FacebookPixelProvider())->getDisplayName());
    }
}
