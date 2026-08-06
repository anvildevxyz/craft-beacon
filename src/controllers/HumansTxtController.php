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
            static fn(Site $site): string => Plugin::$plugin->publicFiles->humansTxt($site)
                ?? throw new NotFoundHttpException(),
        );
    }
}
