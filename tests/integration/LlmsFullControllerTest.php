<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\controllers\LlmsTxtController;
use anvildev\beacon\Plugin;
use anvildev\beacon\records\LlmsSettingsRecord;
use Craft;
use craft\test\TestCase;
use ReflectionObject;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class LlmsFullControllerTest extends TestCase
{
    public function testActionFullThrowsNotFoundWhenBodyMissing(): void
    {
        $this->clearSiteSettingsCache();

        // No llms full body is configured for the site, so the endpoint is "not found".
        $this->expectException(NotFoundHttpException::class);
        (new LlmsTxtController('llms-txt', Craft::$app))->actionFull();
    }

    public function testActionFullRespondsAsMarkdownWhenBodyAvailable(): void
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        $record = LlmsSettingsRecord::findOne(['siteId' => $site->id])
            ?? new LlmsSettingsRecord(['siteId' => $site->id]);
        $record->enabled = true;
        $record->fullBody = "# Beacon\n\nFull body markdown for the llms-full endpoint.";
        $record->save(false);
        $this->clearSiteSettingsCache();

        $response = (new LlmsTxtController('llms-txt', Craft::$app))->actionFull();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('text/markdown', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Full body markdown', (string) $response->content);
    }

    /**
     * SiteSettingsService memoises per (kind, site) for the request, and the app
     * (and its service singletons) outlive each transaction-isolated test. Reset
     * the memo so getLlms() reads the row this test just wrote/cleared.
     */
    private function clearSiteSettingsCache(): void
    {
        $service = Plugin::getInstance()->siteSettings;
        $property = (new ReflectionObject($service))->getProperty('cache');
        $property->setAccessible(true);
        $property->setValue($service, []);
    }
}
