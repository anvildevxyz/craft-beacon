<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\RawResponse;
use anvildev\beacon\Plugin;
use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Serves the per-site TDMRep manifest at `/.well-known/tdmrep.json`
 * (W3C Text & Data Mining Reservation Protocol). Returns 404 when nothing on
 * the site reserves rights, so a default `allow` install exposes no manifest.
 *
 * @see https://www.w3.org/community/reports/tdmrep/CG-FINAL-tdmrep-20240202/
 */
class TdmRepController extends Controller
{
    public array|int|bool $allowAnonymous = true;
    public $enableCsrfValidation = false;

    public function actionIndex(): Response
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        $plugin = Plugin::$plugin;
        $settings = $plugin->settings->get();

        $scopes = $plugin->aiUsage->gatherSectionScopes($site->id, $settings->sectionSeoDefaults);

        if (!$plugin->aiUsage->hasAnyRestrictive($settings->aiUsagePolicy, $scopes['policies'])) {
            throw new NotFoundHttpException();
        }

        $manifest = $plugin->aiUsage->tdmRepManifest(
            $settings->aiUsagePolicy,
            $scopes['policies'],
            $scopes['prefixes'],
            $settings->aiUsagePolicyUrl,
        );

        return RawResponse::build(
            'application/tdmrep+json; charset=UTF-8',
            Json::encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            cacheTags: ['beacon-tdmrep', "beacon-site-{$site->id}"],
        );
    }
}
