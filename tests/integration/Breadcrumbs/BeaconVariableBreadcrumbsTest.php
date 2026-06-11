<?php

namespace anvildev\beacon\tests\integration\Breadcrumbs;

use anvildev\beacon\models\BreadcrumbSettings;
use anvildev\beacon\Plugin;
use anvildev\beacon\variables\BeaconVariable;
use Craft;
use craft\test\TestCase;

/**
 * @group requires-craft
 */
final class BeaconVariableBreadcrumbsTest extends TestCase
{
    public function testBreadcrumbsReturnsHomeWhenNoEntry(): void
    {
        $variable = new BeaconVariable();
        $crumbs = $variable->breadcrumbs();
        $this->assertCount(1, $crumbs);
        $this->assertSame('Home', $crumbs[0]['name']);
    }

    public function testSetBreadcrumbsOverridesAuto(): void
    {
        $variable = new BeaconVariable();
        $variable->setBreadcrumbs([
            ['name' => 'Custom Home', 'url' => 'https://example.com/'],
            ['name' => 'Custom Page'],
        ]);
        $crumbs = $variable->breadcrumbs();
        $this->assertSame('Custom Home', $crumbs[0]['name']);
        $this->assertSame('Custom Page', $crumbs[1]['name']);
    }

    public function testDisabledPerSiteReturnsEmpty(): void
    {
        $primarySiteId = Craft::$app->sites->getPrimarySite()->id;
        $current = Plugin::getInstance()->siteSettings->getBreadcrumbs($primarySiteId);
        $disabled = new BreadcrumbSettings(
            siteId: $primarySiteId,
            enabled: false,
            homeLabel: $current->homeLabel,
        );
        Plugin::getInstance()->siteSettings->saveBreadcrumbs($disabled);

        try {
            $crumbs = (new BeaconVariable())->breadcrumbs();
            $this->assertSame([], $crumbs);
        } finally {
            Plugin::getInstance()->siteSettings->saveBreadcrumbs($current);
        }
    }
}
