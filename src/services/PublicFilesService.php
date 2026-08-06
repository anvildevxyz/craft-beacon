<?php

namespace anvildev\beacon\services;

use anvildev\beacon\enums\RenderCacheType;
use anvildev\beacon\events\RegisterSitemapUrlsEvent;
use anvildev\beacon\helpers\Assets;
use anvildev\beacon\helpers\SeoFieldReader;
use anvildev\beacon\models\AiCrawlerRule;
use anvildev\beacon\models\SitemapSettings;
use anvildev\beacon\Plugin;
use anvildev\beacon\services\llms\MarkdownBudgetTrimmer;
use anvildev\beacon\services\llms\TokenBudgetResult;
use Craft;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\models\Site;
use yii\base\Component;
use yii\base\Event;

/**
 * Site-parameterized renderers for Beacon's public discovery files:
 * robots.txt, sitemap.xml (+ chunks), llms.txt, llms-full.txt, ads.txt,
 * and humans.txt.
 *
 * Extracted from the per-file controllers so the same bodies serve both
 * over HTTP and through GraphQL (`beaconFiles`) — headless frontends fetch
 * the content here and re-serve it from their own domain (issue #37). The
 * controllers keep the HTTP concerns (response headers, CDN cache tags,
 * 404s); this service owns content building and render caching, so both
 * transports share one cache row per file.
 *
 * @phpstan-import-type SitemapRow from \anvildev\beacon\services\SitemapService
 */
class PublicFilesService extends Component
{
    /**
     * Renders the robots.txt body: the site's user-agent rules merged with
     * the enabled AI-crawler rules and Content-Signal lines.
     */
    public function robotsTxt(Site $site): string
    {
        $plugin = Plugin::$plugin;
        $settings = $plugin->siteSettings->getRobots($site->id);

        $aiRules = array_map(
            static fn(AiCrawlerRule $r) => ['bot' => $r->botName, 'allow' => $r->allowPaths, 'disallow' => $r->disallowPaths],
            $plugin->aiCrawlers->getEnabledRules(),
        );

        $globalSettings = $plugin->settings->get();
        $scopes = $plugin->aiUsage->gatherSectionScopes($site->id, $globalSettings->sectionSeoDefaults);
        $contentSignalLines = $plugin->aiUsage->contentSignalLines(
            $globalSettings->aiUsagePolicy,
            $scopes['policies'],
            $scopes['prefixes'],
        );

        return $plugin->robots->render(
            $settings->userAgentRules,
            $aiRules,
            $settings->sitemapUrl === 'auto' ? UrlHelper::siteUrl('sitemap.xml', null, null, $site->id) : $settings->sitemapUrl,
            $contentSignalLines,
        );
    }

    /**
     * The master sitemap.xml document — a urlset, or a sitemap index when
     * the URL count exceeds the per-file cap. Served from the render cache,
     * rebuilding under a mutex on a cold cache.
     */
    public function sitemapMaster(Site $site): string
    {
        $cache = Plugin::$plugin->renderCache;
        $cached = $cache->get($site->id, RenderCacheType::Sitemap, null)
            ?? $cache->get($site->id, RenderCacheType::Sitemap, 'index');
        return $cached?->content ?? $this->mutexedSitemapRebuild($site, null);
    }

    /**
     * A chunked sub-sitemap (`sitemap-{$part}.xml`). Returns null when the
     * part doesn't exist — i.e. the sitemap isn't chunked or the number is
     * out of range.
     */
    public function sitemapPart(Site $site, int $part): ?string
    {
        if ($part < 1) {
            return null;
        }
        $cache = Plugin::$plugin->renderCache;
        $key = 'p:' . $part;

        $cached = $cache->get($site->id, RenderCacheType::Sitemap, $key);
        if ($cached !== null) {
            return $cached->content;
        }

        $this->mutexedSitemapRebuild($site, $key);

        return $cache->get($site->id, RenderCacheType::Sitemap, $key)?->content;
    }

    /**
     * Renders the llms.txt index body for the site, or null when llms.txt
     * is disabled. Cached in the render cache (shared with the HTTP route).
     */
    public function llmsTxt(Site $site): ?string
    {
        $plugin = Plugin::$plugin;
        $settings = $plugin->siteSettings->getLlms($site->id);
        if (!$settings->enabled) {
            return null;
        }

        return $plugin->renderCache->getOrRebuild(
            $site->id,
            RenderCacheType::LlmsTxt,
            null,
            function() use ($site, $settings): string {
                $trust = array_filter([
                    'policyUrl' => $settings->policyUrl,
                    'licenseUrl' => $settings->licenseUrl,
                    'contactEmail' => $settings->contactEmail,
                    'preferredAttribution' => $settings->preferredAttribution,
                ], static fn($v) => $v !== null && $v !== '');

                return Plugin::$plugin->llmsTxt->render(
                    siteName: (string) ($settings->siteNameOverride ?? $site->name),
                    summary: is_string($settings->summary) ? $settings->summary : null,
                    sections: $this->collectLlmsSections($site->id, $settings->sections),
                    trust: $trust,
                );
            },
        );
    }

    /**
     * The llms-full.txt body trimmed to the configured token budget, or
     * null when disabled/empty. The result carries the token estimate for
     * the HTTP route's `X-Token-Estimate` header.
     */
    public function llmsFull(Site $site): ?TokenBudgetResult
    {
        $settings = Plugin::$plugin->siteSettings->getLlms($site->id);
        $fullBody = $settings->fullBody ?? '';
        $body = $settings->enabled && trim($fullBody) !== '' ? $fullBody : null;
        if ($body === null) {
            return null;
        }

        return (new MarkdownBudgetTrimmer(Plugin::$plugin->tokenEstimator))
            ->trim($body, (int) ($settings->llmsFullTokenBudget ?? 0));
    }

    /**
     * The configured ads.txt body (asset upload wins over inline body), or
     * null when disabled/empty.
     */
    public function adsTxt(Site $site): ?string
    {
        $settings = Plugin::$plugin->siteSettings->getAds($site->id);
        if (!$settings->enabled) {
            return null;
        }
        $body = ($settings->assetId !== null
            ? Assets::findById((int) $settings->assetId)?->getContents() ?? ''
            : ''
        ) ?: (is_string($settings->body) ? trim($settings->body) : '');
        return $body !== '' ? $body : null;
    }

    /**
     * The configured humans.txt body, or null when disabled/empty.
     */
    public function humansTxt(Site $site): ?string
    {
        $settings = Plugin::$plugin->siteSettings->getHumans($site->id);
        $body = $settings->enabled && is_string($settings->body) ? trim($settings->body) : '';
        return $body !== '' ? $body : null;
    }

    /**
     * Wraps the sitemap rebuild in a per-site mutex so a stampede of
     * cold-cache requests doesn't run N parallel rebuilds. Concurrent
     * callers wait up to 5s for the lock holder, then re-read the freshly
     * populated cache instead of rebuilding themselves.
     *
     * The `$rereadKey` argument matters for the chunked-sitemap branch:
     *   - `null` (master document) — re-read the index/master row
     *   - `'p:N'` — re-read a specific chunk row populated by the rebuild
     *
     * If the mutex can't be acquired within 5s (rare: lock backend down),
     * we fall back to running the rebuild ourselves rather than failing.
     * Sitemap availability outranks cache-stampede prevention in priority.
     */
    private function mutexedSitemapRebuild(Site $site, ?string $rereadKey): string
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

        $core = $this->collectSitemapEntries($site->id, $settings);

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
    private function collectSitemapEntries(int $siteId, SitemapSettings $settings): array
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
     * @param list<string> $sectionHandles
     * @return array<string, list<array{title:string, url:string, description:?string}>>
     */
    private function collectLlmsSections(int $siteId, array $sectionHandles): array
    {
        $result = [];
        foreach ($sectionHandles as $handle) {
            // Skip Commerce/integration placeholder handles (e.g. __products__) — they
            // cause QueryAbortedException in Craft's ElementQuery.
            if (str_starts_with($handle, '__') && str_ends_with($handle, '__')) {
                continue;
            }
            $list = [];
            $entries = Entry::find()
                ->section($handle)
                ->siteId($siteId)
                ->status(Entry::STATUS_LIVE)
                ->orderBy(['dateUpdated' => SORT_DESC])
                ->limit(5000);
            foreach ($entries->each(500) as $entry) {
                assert($entry instanceof Entry);
                $url = SeoFieldReader::indexableUrl($entry);
                if ($url === null) {
                    continue;
                }
                $list[] = [
                    'title' => (string) $entry->title,
                    'url' => $url,
                    'description' => SeoFieldReader::readDescriptionFor($entry),
                ];
            }
            if ($list !== []) {
                $result[$handle] = $list;
            }
        }
        return $result;
    }
}
