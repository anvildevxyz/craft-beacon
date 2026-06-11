<?php

namespace anvildev\beacon\tests\unit\Tracking\Providers;

use anvildev\beacon\enums\TrackingPlacement;
use anvildev\beacon\tracking\providers\CustomScriptProvider;
use PHPUnit\Framework\TestCase;

final class CustomScriptProviderTest extends TestCase
{
    public function testHandle(): void
    {
        $this->assertSame('custom', (new CustomScriptProvider())->getHandle());
    }

    public function testRenderEmitsHtmlVerbatim(): void
    {
        $provider = new CustomScriptProvider();
        $html = '<script>console.log("custom");</script>';
        $this->assertSame($html, $provider->render(['html' => $html], TrackingPlacement::Head));
    }

    public function testValidateRejectsEmptyHtml(): void
    {
        $errors = (new CustomScriptProvider())->validateConfig(['html' => '']);
        $this->assertArrayHasKey('html', $errors);
    }

    public function testValidateAcceptsNonEmptyHtml(): void
    {
        $errors = (new CustomScriptProvider())->validateConfig(['html' => '<script></script>']);
        $this->assertSame([], $errors);
    }

    public function testFixedPlacementsIsNullForFlexibleProvider(): void
    {
        $this->assertNull((new CustomScriptProvider())->getFixedPlacements());
    }

    public function testGetDisplayNameIsNonEmpty(): void
    {
        $this->assertNotSame('', (new CustomScriptProvider())->getDisplayName());
    }
}
