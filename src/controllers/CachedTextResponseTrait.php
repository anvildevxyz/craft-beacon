<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\enums\RenderCacheType;
use anvildev\beacon\helpers\RawResponse;
use anvildev\beacon\Plugin;
use Craft;
use craft\models\Site;
use yii\web\Response;

/**
 * Shared skeleton for the public per-site text/JSON discovery endpoints
 * (ads.txt, humans.txt, llms.txt, schemamap.json): resolve the current site,
 * serve the render-cached body (rebuilding on miss via the builder), and wrap
 * it in a {@see RawResponse} tagged for per-site CDN invalidation.
 */
trait CachedTextResponseTrait
{
    /**
     * @param callable(Site): string $build Produces the body on cache miss.
     * @param ?int $ttlSeconds Optional origin-store staleness cap for content
     *   whose inputs can change without firing an element event (see
     *   RenderCacheService::get()). Distinct from $maxAge, the HTTP header.
     */
    private function cachedTextResponse(
        RenderCacheType $type,
        string $contentType,
        string $kindTag,
        callable $build,
        int $maxAge = 300,
        ?int $ttlSeconds = null,
    ): Response {
        $site = Craft::$app->getSites()->getCurrentSite();
        $body = Plugin::$plugin->renderCache->getOrRebuild(
            $site->id,
            $type,
            null,
            fn(): string => $build($site),
            $ttlSeconds,
        );

        return RawResponse::build(
            $contentType,
            $body,
            $maxAge,
            cacheTags: [$kindTag, "beacon-site-{$site->id}"],
        );
    }
}
