<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\enums\RenderCacheType;
use anvildev\beacon\helpers\Assets;
use anvildev\beacon\Plugin;
use craft\models\Site;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class AdsTxtController extends Controller
{
    use CachedTextResponseTrait;

    public array|int|bool $allowAnonymous = true;
    public $enableCsrfValidation = false;

    /**
     * Serves the configured ads.txt body for the current site.
     *
     * @throws \yii\web\NotFoundHttpException when ads.txt is disabled or has no body
     */
    public function actionIndex(): Response
    {
        return $this->cachedTextResponse(
            RenderCacheType::Ads,
            'text/plain; charset=UTF-8',
            'beacon-ads',
            function(Site $site): string {
                $settings = Plugin::$plugin->siteSettings->getAds($site->id);
                if (!$settings->enabled) {
                    throw new NotFoundHttpException();
                }
                $body = ($settings->assetId !== null
                    ? Assets::findById((int) $settings->assetId)?->getContents() ?? ''
                    : ''
                ) ?: (is_string($settings->body) ? trim($settings->body) : '');
                if ($body === '') {
                    throw new NotFoundHttpException();
                }
                return $body;
            },
        );
    }
}
