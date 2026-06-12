<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\enums\RenderCacheType;
use anvildev\beacon\helpers\Assets;
use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\helpers\Strings;
use anvildev\beacon\models\AdsSettings;
use anvildev\beacon\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

class AdsSettingsController extends Controller
{
    use AssetSelectorTrait;
    use BeaconCpPermissionTrait;
    use SiteScopedCpControllerTrait;

    protected const BEACON_PERMISSION = BeaconPermissions::EDIT_CRAWLERS;

    /**
     * Renders the ads.txt settings form for the current (or selected) site.
     */
    public function actionIndex(): Response
    {
        $site = $this->resolveSite();
        $settings = Plugin::$plugin->siteSettings->getAds($site->id);
        $asset = $settings->assetId !== null
            ? Assets::findById((int) $settings->assetId)
            : null;

        return $this->renderTemplate('beacon/crawlers/index', [
            'selectedCrawlerTab' => 'ads-txt',
            'site' => $site,
            'settings' => $settings,
            'currentAsset' => $asset,
            'sites' => Craft::$app->getSites()->getAllSites(),
        ]);
    }

    /**
     * Persists the posted ads.txt settings for a site, invalidates the cached
     * ads.txt render, and redirects back to the settings screen.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $request = Http::request();
        $plugin = Plugin::$plugin;
        $siteId = $this->resolveSiteIdFromPost();

        $plugin->siteSettings->saveAds(new AdsSettings(
            siteId: $siteId,
            enabled: (bool) $request->getBodyParam('enabled', false),
            assetId: $this->assetIdFromSelector($request->getBodyParam('assetId')),
            body: Strings::trimToNull($request->getBodyParam('body')),
        ));

        return $this->finishSiteScopedSave(
            Craft::t('beacon', 'flash.adsTxt.ads.txt.settings.saved'),
            'beacon/crawlers/ads-txt',
            $siteId,
            RenderCacheType::Ads,
        );
    }
}
