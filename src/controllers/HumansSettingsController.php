<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\enums\RenderCacheType;
use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\helpers\Strings;
use anvildev\beacon\models\HumansSettings;
use anvildev\beacon\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

class HumansSettingsController extends Controller
{
    use BeaconCpPermissionTrait;
    use SiteScopedCpControllerTrait;

    protected const BEACON_PERMISSION = BeaconPermissions::EDIT_CRAWLERS;

    /**
     * Renders the humans.txt settings form for the current (or selected) site.
     */
    public function actionIndex(): Response
    {
        $site = $this->resolveSite();
        return $this->renderTemplate('beacon/crawlers/index', [
            'selectedCrawlerTab' => 'humans-txt',
            'site' => $site,
            'settings' => Plugin::$plugin->siteSettings->getHumans($site->id),
            'sites' => Craft::$app->getSites()->getAllSites(),
        ]);
    }

    /**
     * Persists the posted humans.txt settings for a site, invalidates the cached
     * humans.txt render, and redirects back to the settings screen.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $request = Http::request();
        $plugin = Plugin::$plugin;
        $siteId = $this->resolveSiteIdFromPost();

        $plugin->siteSettings->saveHumans(new HumansSettings(
            siteId: $siteId,
            enabled: (bool) $request->getBodyParam('enabled', false),
            body: Strings::trimToNull($request->getBodyParam('body')),
        ));

        return $this->finishSiteScopedSave(
            Craft::t('beacon', 'flash.humansTxt.humans.txt.settings.saved'),
            'beacon/crawlers/humans-txt',
            $siteId,
            RenderCacheType::Humans,
        );
    }
}
