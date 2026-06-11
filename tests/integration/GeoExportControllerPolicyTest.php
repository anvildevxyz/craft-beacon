<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\controllers\GeoExportController;
use anvildev\beacon\models\Settings;
use anvildev\beacon\Plugin;
use Craft;
use craft\test\TestCase;
use yii\web\NotFoundHttpException;

/**
 * @group requires-craft
 */
class GeoExportControllerPolicyTest extends TestCase
{
    public function testActionIndexReturnsNotFoundForUnknownEntryId(): void
    {
        if (!class_exists(\Craft::class) || Craft::$app === null) {
            $this->markTestSkipped('Requires initialized Craft app.');
        }

        $controller = new GeoExportController('geo-export', Craft::$app);
        $this->expectException(NotFoundHttpException::class);
        $controller->actionIndex(PHP_INT_MAX);
    }

    public function testActionMdReturnsNotFoundWhenMdSuffixModeDisabled(): void
    {
        if (!class_exists(\Craft::class) || Craft::$app === null || Plugin::getInstance() === null) {
            $this->markTestSkipped('Requires initialized Craft + plugin instance.');
        }

        $plugin = Plugin::getInstance();
        $previous = clone $plugin->settings->get();

        try {
            $plugin->settings->save($this->withGeoPolicy($previous, true, false));

            $controller = new GeoExportController('geo-export', Craft::$app);
            $this->expectException(NotFoundHttpException::class);
            $controller->actionMd('this-uri-does-not-exist');
        } finally {
            $plugin->settings->save($previous);
        }
    }

    public function testActionMdReturnsNotFoundForMissingEntryWhenEnabled(): void
    {
        if (!class_exists(\Craft::class) || Craft::$app === null || Plugin::getInstance() === null) {
            $this->markTestSkipped('Requires initialized Craft + plugin instance.');
        }

        $plugin = Plugin::getInstance();
        $previous = clone $plugin->settings->get();

        try {
            $plugin->settings->save($this->withGeoPolicy($previous, true, true));

            $controller = new GeoExportController('geo-export', Craft::$app);
            $this->expectException(NotFoundHttpException::class);
            $controller->actionMd('this-uri-does-not-exist');
        } finally {
            $plugin->settings->save($previous);
        }
    }

    private function withGeoPolicy(Settings $settings, bool $enabled, bool $mdSuffixEnabled): Settings
    {
        $copy = clone $settings;
        $copy->geoMarkdownEnabled = $enabled;
        $copy->geoMarkdownMdSuffixEnabled = $mdSuffixEnabled;
        return $copy;
    }
}
