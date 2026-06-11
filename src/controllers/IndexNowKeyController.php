<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\RawResponse;
use anvildev\beacon\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Serves the IndexNow ownership-proof file at `/{key}.txt`.
 *
 * The IndexNow spec requires that the API key the caller submits also be
 * fetchable as plain text at this URL. Search engines (Bing/Yandex/Naver/
 * Seznam) hit it once on first submission to verify the submitter actually
 * controls the host.
 *
 * Routing is dynamic — every site can carry a different key, so the route
 * pattern accepts any `*.txt` filename and this controller does the lookup.
 * A miss returns 404 so unrelated `.txt` requests fall through to whatever
 * the rest of the URL manager wants to do with them.
 */
class IndexNowKeyController extends Controller
{
    public array|int|bool $allowAnonymous = true;
    public $enableCsrfValidation = false;

    /**
     * @throws NotFoundHttpException when the requested filename doesn't match
     *   this site's configured IndexNow key.
     */
    public function actionFile(string $key): Response
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        $configured = trim((string) Plugin::$plugin->siteSettings->getWebmaster($site->id)->indexNowKey);

        if ($configured === '' || !hash_equals($configured, $key)) {
            throw new NotFoundHttpException();
        }

        return RawResponse::build(
            'text/plain; charset=UTF-8',
            $configured,
            86400,
            cacheTags: ['beacon-indexnow-key', "beacon-site-{$site->id}"],
        );
    }
}
