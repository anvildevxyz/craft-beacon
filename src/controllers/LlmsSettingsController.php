<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\enums\RenderCacheType;
use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\helpers\Strings;
use anvildev\beacon\models\LlmsSettings;
use anvildev\beacon\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

class LlmsSettingsController extends Controller
{
    use BeaconCpPermissionTrait;
    use SiteScopedCpControllerTrait;

    protected const BEACON_PERMISSION = BeaconPermissions::EDIT_CRAWLERS;

    /**
     * Renders the llms.txt settings form for the current (or selected) site.
     */
    public function actionIndex(): Response
    {
        $site = $this->resolveSite();
        return $this->renderTemplate('beacon/crawlers/index', [
            'selectedCrawlerTab' => 'llms-txt',
            'site' => $site,
            'settings' => Plugin::$plugin->siteSettings->getLlms($site->id),
            'allSections' => $this->collectSections(),
            'sites' => Craft::$app->getSites()->getAllSites(),
        ]);
    }

    /**
     * Persists the posted llms.txt settings for a site, invalidates the cached
     * llms.txt render, and redirects back to the settings screen.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $request = Http::request();
        $plugin = Plugin::$plugin;
        $siteId = $this->resolveSiteIdFromPost();
        $get = static fn(string $k) => Strings::trimToNull($request->getBodyParam($k));

        $fullBody = $get('fullBody');
        if ($fullBody !== null && strlen($fullBody) > 524288) {
            Craft::$app->getSession()->setError(Craft::t('beacon', 'llms-full.txt body exceeds the 512 KiB limit.'));
            return null;
        }

        $plugin->siteSettings->saveLlms(new LlmsSettings(
            siteId: $siteId,
            enabled: (bool) $request->getBodyParam('enabled', false),
            summary: $get('summary'),
            siteNameOverride: $get('siteNameOverride'),
            sections: $this->normalizeStringArray($request->getBodyParam('sections', [])),
            policyUrl: $get('policyUrl'),
            licenseUrl: $get('licenseUrl'),
            contactEmail: $get('contactEmail'),
            preferredAttribution: $get('preferredAttribution'),
            fullBody: $fullBody,
        ));

        return $this->finishSiteScopedSave(
            Craft::t('beacon', 'llms.txt settings saved.'),
            'beacon/crawlers/llms-txt',
            $siteId,
            RenderCacheType::LlmsTxt,
        );
    }
}
