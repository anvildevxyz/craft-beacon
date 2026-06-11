<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\enums\RenderCacheType;
use anvildev\beacon\Plugin;
use craft\helpers\Json;
use craft\models\Site;
use craft\web\Controller;
use yii\web\Response;

/**
 * Site-level structured-data aggregation endpoint.
 *
 * Public, anonymous, GET-only. Renders `GET /beacon/schemamap.json` — a
 * JSON-LD graph listing every public entry as a `WebPage` reference under a
 * site-level `Collection`, plus the site's `WebSite` + `Organization` identity
 * nodes. Pairs with `llms.txt` (markdown index) and `sitemap.xml` (URL list)
 * as the JSON-LD-flavoured discovery surface AI agents can crawl in one round
 * trip.
 */
class SchemamapController extends Controller
{
    use CachedTextResponseTrait;

    public array|int|bool $allowAnonymous = true;
    public $enableCsrfValidation = false;

    /**
     * Renders and serves the cached schemamap.json JSON-LD graph for the current site.
     */
    public function actionIndex(): Response
    {
        // Origin TTL: the map folds in identity settings, whose changes
        // don't fire element events — cap staleness at 30 minutes.
        return $this->cachedTextResponse(
            RenderCacheType::Schemamap,
            'application/ld+json; charset=UTF-8',
            'beacon-schemamap',
            static fn(Site $site): string => Json::encode(
                Plugin::$plugin->schemamap->buildMap($site),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
            ),
            maxAge: 1800,
            ttlSeconds: 1800,
        );
    }
}
