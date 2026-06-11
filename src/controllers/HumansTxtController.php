<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\enums\RenderCacheType;
use anvildev\beacon\Plugin;
use craft\models\Site;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class HumansTxtController extends Controller
{
    use CachedTextResponseTrait;

    public array|int|bool $allowAnonymous = true;
    public $enableCsrfValidation = false;

    /**
     * Serves the configured humans.txt body for the current site.
     *
     * @throws \yii\web\NotFoundHttpException when humans.txt is disabled or has no body
     */
    public function actionIndex(): Response
    {
        return $this->cachedTextResponse(
            RenderCacheType::Humans,
            'text/plain; charset=UTF-8',
            'beacon-humans',
            function(Site $site): string {
                $settings = Plugin::$plugin->siteSettings->getHumans($site->id);
                $body = $settings->enabled && is_string($settings->body) ? trim($settings->body) : '';
                if ($body === '') {
                    throw new NotFoundHttpException();
                }
                return $body;
            },
        );
    }
}
