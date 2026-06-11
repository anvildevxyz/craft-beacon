<?php

namespace anvildev\beacon\tests\unit\Breadcrumbs;

use anvildev\beacon\models\BreadcrumbSettings;
use PHPUnit\Framework\TestCase;

final class BreadcrumbSettingsTest extends TestCase
{
    public function testHoldsCoreAttributes(): void
    {
        $settings = new BreadcrumbSettings(siteId: 1, enabled: true, homeLabel: 'Home');
        $this->assertSame(1, $settings->siteId);
        $this->assertTrue($settings->enabled);
        $this->assertSame('Home', $settings->homeLabel);
    }

    public function testDefaultsForLabel(): void
    {
        $settings = new BreadcrumbSettings(siteId: 2);
        $this->assertTrue($settings->enabled);
        $this->assertSame('Home', $settings->homeLabel);
    }
}
