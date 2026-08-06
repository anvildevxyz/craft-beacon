<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\RawResponse;
use anvildev\beacon\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * HTTP transport for the sitemap documents. Content building + render
 * caching live in {@see \anvildev\beacon\services\PublicFilesService},
 * shared with the `beaconFiles` GraphQL query.
 */
class SitemapController extends Controller
{
    public array|int|bool $allowAnonymous = true;
    public $enableCsrfValidation = false;

    /**
     * Serves the master sitemap.xml (or sitemap index) for the current site,
     * rebuilding the cache under a mutex on a cold cache.
     */
    public function actionIndex(): Response
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        $tags = ['beacon-sitemap', "beacon-site-{$site->id}"];

        return $this->xmlResponse(Plugin::$plugin->publicFiles->sitemapMaster($site), $tags);
    }

    /**
     * Serves chunked sub-sitemaps: `sitemap-1.xml`, `sitemap-2.xml`, …
     *
     * @throws NotFoundHttpException
     */
    public function actionPart(int $part): Response
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        $tags = ['beacon-sitemap', "beacon-sitemap-part-{$part}", "beacon-site-{$site->id}"];

        $xml = Plugin::$plugin->publicFiles->sitemapPart($site, $part);
        if ($xml === null) {
            throw new NotFoundHttpException();
        }
        return $this->xmlResponse($xml, $tags);
    }

    /**
     * Sitemap-XML response. We bump max-age to 1 hour rather than the
     * `RawResponse` default of 5 minutes — sitemaps are crawled at most a
     * few times a day per search engine, and shorter TTLs only inflate cold
     * regen cost without changing crawler behaviour. `stale-while-revalidate`
     * lets a CDN serve the stale XML for up to 24h while origin rebuilds.
     *
     * @param list<string> $cacheTags
     */
    private function xmlResponse(string $xml, array $cacheTags = []): Response
    {
        return RawResponse::build('application/xml; charset=UTF-8', $xml, 3600, cacheTags: $cacheTags);
    }
}
