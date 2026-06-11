<?php

namespace anvildev\beacon\tests\unit\Tracking\Providers;

use anvildev\beacon\enums\TrackingPlacement;
use anvildev\beacon\enums\TrackingProvider;
use anvildev\beacon\tracking\providers\MatomoProvider;
use PHPUnit\Framework\TestCase;

final class MatomoProviderTest extends TestCase
{
    public function testValidateRejectsNonHttpsUrl(): void
    {
        $errors = (new MatomoProvider())->validateConfig([
            'matomoUrl' => 'http://matomo.example.com',
            'siteId' => '1',
        ]);
        $this->assertArrayHasKey('matomoUrl', $errors);
    }

    public function testValidateRejectsNonPositiveSiteId(): void
    {
        $errors = (new MatomoProvider())->validateConfig([
            'matomoUrl' => 'https://matomo.example.com',
            'siteId' => '0',
        ]);
        $this->assertArrayHasKey('siteId', $errors);
    }

    public function testValidateAcceptsValid(): void
    {
        $this->assertSame([], (new MatomoProvider())->validateConfig([
            'matomoUrl' => 'https://matomo.example.com',
            'siteId' => '7',
        ]));
    }

    public function testRenderBodyEndContainsTrackerSrc(): void
    {
        $html = (new MatomoProvider())->render([
            'matomoUrl' => 'https://matomo.example.com',
            'siteId' => '7',
        ], TrackingPlacement::BodyEnd);
        
        
        $this->assertStringContainsString('matomo.example.com', $html);
        $this->assertStringContainsString("'matomo.js'", $html);
        $this->assertStringContainsString("setSiteId', '7'", $html);
    }

    public function testBodyEndOutputMatchesSnapshot(): void
    {
        $rendered = (new MatomoProvider())->render([
            'matomoUrl' => 'https://matomo.example.com',
            'siteId' => '7',
        ], TrackingPlacement::BodyEnd);
        $snapshot = file_get_contents(dirname(__DIR__, 3) . '/snapshots/tracking/matomo-bodyEnd.html');
        $this->assertSame($snapshot, $rendered);
    }

    public function testGetHandleMatchesEnum(): void
    {
        $this->assertSame(TrackingProvider::Matomo->value, (new MatomoProvider())->getHandle());
    }

    public function testGetDisplayNameIsNonEmpty(): void
    {
        $this->assertNotSame('', (new MatomoProvider())->getDisplayName());
    }

    public function testGetFixedPlacementsIsNull(): void
    {
        $this->assertNull((new MatomoProvider())->getFixedPlacements());
    }
}
