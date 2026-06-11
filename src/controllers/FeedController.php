<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\RawResponse;
use anvildev\beacon\Plugin;
use anvildev\beacon\services\FeedService;
use Craft;
use craft\elements\Entry;
use craft\models\Site;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class FeedController extends Controller
{
    public array|int|bool $allowAnonymous = true;
    public $enableCsrfValidation = false;

    /**
     * Serves the JSON Feed for a section on the current site.
     *
     * @param string $section the section handle from the route
     * @throws \yii\web\NotFoundHttpException when the section doesn't exist or has no eligible entries
     */
    public function actionJson(string $section): Response
    {
        return $this->renderFeed($section, 'json', 'application/feed+json; charset=UTF-8');
    }

    /**
     * Serves the Atom feed for a section on the current site.
     *
     * @param string $section the section handle from the route
     * @throws \yii\web\NotFoundHttpException when the section doesn't exist or has no eligible entries
     */
    public function actionAtom(string $section): Response
    {
        return $this->renderFeed($section, 'atom', 'application/atom+xml; charset=UTF-8');
    }

    private function renderFeed(string $section, string $format, string $contentType): Response
    {
        [$site, $feeds, $entries, $siteUrl] = $this->prepare($section);
        $feedUrl = rtrim($siteUrl, '/') . "/feed/{$section}.{$format}";
        $body = $format === 'json'
            ? $feeds->renderJsonFeed((string) $site->name, $siteUrl, $feedUrl, $section, $entries)
            : $feeds->renderAtomFeed((string) $site->name, $siteUrl, $feedUrl, $section, $entries);

        Craft::info(
            sprintf(
                '%s feed rendered section=%s siteId=%d entries=%d bytes=%d',
                strtoupper($format),
                $section,
                (int) $site->id,
                count($entries),
                strlen($body),
            ),
            'beacon',
        );
        return RawResponse::build($contentType, $body);
    }

    /**
     * @return array{Site, FeedService, non-empty-list<Entry>, string}
     */
    private function prepare(string $section): array
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        $feeds = Plugin::$plugin->feeds;
        if (!$feeds->sectionExists($section)) {
            throw new NotFoundHttpException();
        }
        $entries = $feeds->fetchEntries($site->id, $section);
        if ($entries === []) {
            throw new NotFoundHttpException();
        }
        return [$site, $feeds, $entries, (string) $site->getBaseUrl()];
    }
}
