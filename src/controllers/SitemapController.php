<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\enums\RenderCacheType;
use anvildev\beacon\events\RegisterSitemapUrlsEvent;
use anvildev\beacon\helpers\RawResponse;
use anvildev\beacon\helpers\SeoFieldReader;
use anvildev\beacon\models\SitemapSettings;
use anvildev\beacon\Plugin;
use Craft;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\models\Site;
use craft\web\Controller;
use yii\base\Event;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * @phpstan-import-type SitemapRow from \anvildev\beacon\services\SitemapService
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
        $cache = Plugin::$plugin->renderCache;
        $tags = ['beacon-sitemap', "beacon-site-{$site->id}"];

        $cached = $cache->get($site->id, RenderCacheType::Sitemap, null)
            ?? $cache->get($site->id, RenderCacheType::Sitemap, 'index');
        return $this->xmlResponse($cached?->content ?? $this->mutexedRebuild($site, null), $tags);
    }

    /**
     * Serves chunked sub-sitemaps: `sitemap-1.xml`, `sitemap-2.xml`, …
     *
     * @throws NotFoundHttpException
     */
    public function actionPart(int $part): Response
    {
        if ($part < 1) {
            throw new NotFoundHttpException();
        }
        $site = Craft::$app->getSites()->getCurrentSite();
        $cache = Plugin::$plugin->renderCache;
        $key = 'p:' . $part;
        $tags = ['beacon-sitemap', "beacon-sitemap-part-{$part}", "beacon-site-{$site->id}"];

        if (($cached = $cache->get($site->id, RenderCacheType::Sitemap, $key)) !== null) {
            return $this->xmlResponse($cached->content, $tags);
        }

        $this->mutexedRebuild($site, $key);

        /** @phpstan-ignore-next-line */
        $rebuilt = $cache->get($site->id, RenderCacheType::Sitemap, $key);
        if ($rebuilt === null) {
            throw new NotFoundHttpException();
        }
        /** @phpstan-ignore-next-line */
        return $this->xmlResponse($rebuilt->content, $tags);
    }

    /**
     * Wraps `rebuildSitemapCachesReturnMasterDocument()` in a per-site mutex
     * so a stampede of cold-cache requests doesn't run N parallel rebuilds.
     * Concurrent callers wait up to 5s for the lock holder, then re-read
     * the freshly-populated cache instead of running the rebuild themselves.
     *
     * The `$rereadKey` argument matters for the chunked-sitemap branch:
     *   - `null` (master document) — re-read the index/master row
     *   - `'p:N'` — re-read a specific chunk row populated by the rebuild
     *
     * If the mutex can't be acquired within 5s (rare: lock backend down),
     * we fall back to running the rebuild ourselves rather than serving 500.
     * Sitemap availability outranks cache-stampede prevention in priority.
     */
    private function mutexedRebuild(Site $site, ?string $rereadKey): string
    {
        $cache = Plugin::$plugin->renderCache;
        $mutex = Craft::$app->getMutex();
        $lockKey = "beacon-sitemap-rebuild:{$site->id}";
        $acquired = $mutex->acquire($lockKey, 5);
        try {
            if ($acquired) {
                $cached = $cache->get($site->id, RenderCacheType::Sitemap, $rereadKey)
                    ?? ($rereadKey === null ? $cache->get($site->id, RenderCacheType::Sitemap, 'index') : null);
                if ($cached !== null) {
                    return $cached->content;
                }
            }
            return $this->rebuildSitemapCachesReturnMasterDocument($site);
        } finally {
            if ($acquired) {
                $mutex->release($lockKey);
            }
        }
    }

    private function rebuildSitemapCachesReturnMasterDocument(Site $site): string
    {
        $plugin = Plugin::$plugin;
        $cache = $plugin->renderCache;
        $sitemap = $plugin->sitemap;
        $settings = $plugin->siteSettings->getSitemap($site->id);
        $priority = $settings->priority;
        $changefreq = $settings->changefreq;

        $core = $this->collectEntries($site->id, $settings);

        $event = new RegisterSitemapUrlsEvent($site);
        Event::trigger(Plugin::class, Plugin::EVENT_REGISTER_SITEMAP_URLS, $event);

        $merged = $sitemap->mergeCoreAndExtras($core, $event->getExtras(), $priority, $changefreq);
        $maxUrls = $sitemap->effectiveMaxUrlsPerFile(null);

        $cache->flush($site->id, RenderCacheType::Sitemap);

        if ($merged === [] || count($merged) <= $maxUrls) {
            $xml = $sitemap->renderUrlset($merged, $priority, $changefreq);
            $cache->set($site->id, RenderCacheType::Sitemap, null, $xml);
            return $xml;
        }

        $indexRows = [];
        foreach ($sitemap->chunkRows($merged, $maxUrls) as $i => $chunk) {
            $n = $i + 1;
            $cache->set($site->id, RenderCacheType::Sitemap, 'p:' . $n, $sitemap->renderUrlset($chunk, $priority, $changefreq));
            $indexRows[] = [
                'url' => UrlHelper::siteUrl("sitemap-{$n}.xml", [], null, $site->id),
                'lastmod' => $sitemap->newestLastmodInChunk($chunk),
            ];
        }

        $indexXml = $sitemap->renderIndex($indexRows);
        $cache->set($site->id, RenderCacheType::Sitemap, 'index', $indexXml);
        return $indexXml;
    }

    /**
     * @return list<SitemapRow>
     */
    private function collectEntries(int $siteId, SitemapSettings $settings): array
    {
        $sectionHandles = $settings->includedSectionHandles();
        if ($sectionHandles === []) {
            return [];
        }

        $query = Entry::find()
            ->section($sectionHandles)
            ->siteId($siteId)
            ->status(Entry::STATUS_LIVE)
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->limit(null);

        $result = [];
        foreach ($query->each(500) as $entry) {
            assert($entry instanceof Entry);
            $url = SeoFieldReader::indexableUrl($entry);
            if ($url === null) {
                continue;
            }
            $meta = $settings->resolveForSection($entry->getSection()?->handle ?? '');
            $result[] = [
                'url' => $url,
                'lastmod' => $entry->dateUpdated?->format('c') ?? date('c'),
                'priority' => $meta['priority'],
                'changefreq' => $meta['changefreq'],
            ];
        }
        return $result;
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
