<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\RawResponse;
use anvildev\beacon\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * HTTP transport for llms.txt / llms-full.txt. Content building + render
 * caching live in {@see \anvildev\beacon\services\PublicFilesService},
 * shared with the `beaconFiles` GraphQL query.
 */
class LlmsTxtController extends Controller
{
    public array|int|bool $allowAnonymous = true;
    public $enableCsrfValidation = false;

    /**
     * Renders and serves the llms.txt index for the current site.
     *
     * @throws \yii\web\NotFoundHttpException when llms.txt is disabled for the site
     */
    public function actionIndex(): Response
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        $body = Plugin::$plugin->publicFiles->llmsTxt($site);
        if ($body === null) {
            Craft::info("llms.txt disabled for siteId={$site->id}", 'beacon');
            throw new NotFoundHttpException();
        }

        return RawResponse::build(
            'text/markdown; charset=UTF-8',
            $body,
            1800,
            cacheTags: ['beacon-llms', "beacon-site-{$site->id}"],
        );
    }

    /**
     * Serves the full llms-full.txt body for the current site.
     *
     * @throws \yii\web\NotFoundHttpException when llms-full.txt is disabled or has no body
     */
    public function actionFull(): Response
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        $result = Plugin::$plugin->publicFiles->llmsFull($site);
        if ($result === null) {
            Craft::info("llms-full.txt unavailable for siteId={$site->id}", 'beacon');
            throw new NotFoundHttpException();
        }
        $body = $result->markdown;

        Craft::info(sprintf(
            'llms-full.txt rendered for siteId=%d bytes=%d tokens=%d truncated=%s',
            $site->id,
            strlen($body),
            $result->estimatedTokens,
            $result->truncated ? 'yes' : 'no',
        ), 'beacon');

        $response = RawResponse::build(
            'text/markdown; charset=UTF-8',
            $body,
            cacheTags: ['beacon-llms-full', "beacon-site-{$site->id}"],
        );
        $response->headers->set('X-Token-Estimate', (string) $result->estimatedTokens);
        return $response;
    }
}
