<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\enums\RenderCacheType;
use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\models\WebMcpSettings;
use anvildev\beacon\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * CP settings for the browser-side AI-agent tools script (`/webmcp.js`).
 *
 * @author anvildev
 */
class WebMcpSettingsController extends Controller
{
    use BeaconCpPermissionTrait;
    use SiteScopedCpControllerTrait;

    protected const BEACON_PERMISSION = BeaconPermissions::EDIT_CRAWLERS;

    /**
     * Renders the WebMCP settings form for the current (or selected) site.
     */
    public function actionIndex(): Response
    {
        $site = $this->resolveSite();
        return $this->renderTemplate('beacon/crawlers/index', [
            'selectedCrawlerTab' => 'webmcp',
            'site' => $site,
            'settings' => Plugin::$plugin->siteSettings->getWebMcp($site->id),
            'sites' => Craft::$app->getSites()->getAllSites(),
        ]);
    }

    /**
     * Persists the posted WebMCP settings for a site, invalidates the
     * cached script render, and redirects back to the settings screen.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $request = Http::request();
        $siteId = $this->resolveSiteIdFromPost();

        Plugin::$plugin->siteSettings->saveWebMcp(new WebMcpSettings(
            siteId: $siteId,
            enabled: (bool) $request->getBodyParam('enabled', false),
        ));

        return $this->finishSiteScopedSave(
            Craft::t('beacon', 'flash.webMcp.webmcp.settings.saved'),
            'beacon/crawlers/webmcp',
            $siteId,
            RenderCacheType::WebMcp,
        );
    }
}
