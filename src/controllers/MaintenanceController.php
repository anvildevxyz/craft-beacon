<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\enums\RenderCacheType;
use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

class MaintenanceController extends Controller
{
    use BeaconCpPermissionTrait;
    use SiteScopedCpControllerTrait;

    protected const BEACON_PERMISSION = BeaconPermissions::EDIT_SETTINGS;

    /**
     * Flushes the entire Beacon render cache and redirects back with a notice.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     */
    public function actionFlushCache(): Response
    {
        $this->requirePostRequest();
        Plugin::$plugin->renderCache->flush();
        return $this->done(Craft::t('beacon', 'Beacon render cache flushed.'));
    }

    /**
     * Flushes the primary site's sitemap cache so the next request regenerates it.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     */
    public function actionRegenerateSitemap(): Response
    {
        $this->requirePostRequest();
        $siteId = $this->resolveSiteIdFromPost();
        Plugin::$plugin->renderCache->flush($siteId, RenderCacheType::Sitemap);
        return $this->done(Craft::t('beacon', 'Sitemap cache flushed. Next request will regenerate.'));
    }

    /**
     * Invalidates the current site's llms.txt cache so the next request regenerates it.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     */
    public function actionRegenerateLlmsTxt(): Response
    {
        $this->requirePostRequest();
        $siteId = $this->resolveSiteIdFromPost();
        Plugin::$plugin->renderCache->invalidate($siteId, RenderCacheType::LlmsTxt);
        return $this->done(Craft::t('beacon', 'llms.txt cache invalidated. Next request will regenerate.'));
    }

    /**
     * Invalidates the current site's sitemap, llms.txt, humans.txt, and ads.txt
     * caches so the next requests regenerate them.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     */
    public function actionRegenerateAll(): Response
    {
        $this->requirePostRequest();
        $siteId = $this->resolveSiteIdFromPost();
        $cache = Plugin::$plugin->renderCache;
        $cache->flush($siteId, RenderCacheType::Sitemap);
        foreach ([RenderCacheType::LlmsTxt, RenderCacheType::Humans, RenderCacheType::Ads] as $type) {
            $cache->invalidate($siteId, $type);
        }
        return $this->done(Craft::t('beacon', 'All Beacon text/sitemap caches invalidated. Use `craft beacon/cache/regenerate-all` to warm them.'));
    }

    private function done(string $message): Response
    {
        Craft::$app->getSession()->setNotice($message);
        return $this->redirect('beacon');
    }
}
