<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\RawResponse;
use anvildev\beacon\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * HTTP transport for robots.txt. Content building lives in
 * {@see \anvildev\beacon\services\PublicFilesService}, shared with the
 * `beaconFiles` GraphQL query.
 */
class RobotsController extends Controller
{
    public array|int|bool $allowAnonymous = true;
    public $enableCsrfValidation = false;

    /**
     * Renders and serves the robots.txt body for the current site, merging the
     * site's user-agent rules with the enabled AI-crawler rules.
     */
    public function actionIndex(): Response
    {
        $site = Craft::$app->getSites()->getCurrentSite();

        return RawResponse::build(
            'text/plain; charset=UTF-8',
            Plugin::$plugin->publicFiles->robotsTxt($site),
            cacheTags: ['beacon-robots', "beacon-site-{$site->id}"],
        );
    }
}
