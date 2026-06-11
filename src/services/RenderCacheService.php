<?php

namespace anvildev\beacon\services;

use anvildev\beacon\enums\RenderCacheType;
use anvildev\beacon\helpers\Db;
use anvildev\beacon\models\RenderedOutput;
use anvildev\beacon\records\LlmsSettingsRecord;
use anvildev\beacon\records\RenderCacheRecord;
use anvildev\beacon\records\SitemapSettingsRecord;
use Craft;
use DateTime;
use yii\base\Component;

/**
 * @phpstan-type RenderCacheInvalidationTarget array{siteId:int, type:RenderCacheType, contentKey:?string}
 */
class RenderCacheService extends Component
{
    /** @var array<string,list<RenderCacheInvalidationTarget>>|null */
    private ?array $invalidationMap = null;

    public function get(int $siteId, RenderCacheType $type, ?string $contentKey = null): ?RenderedOutput
    {
        $record = RenderCacheRecord::findOne([
            'siteId' => $siteId,
            'type' => $type->value,
            'contentKey' => $contentKey,
        ]);

        if (!$record) {
            return null;
        }

        $output = new RenderedOutput(
            content: $record->content,
            generatedAt: new DateTime($record->generatedAt),
            validUntil: $record->validUntil ? new DateTime($record->validUntil) : null,
        );

        // A lapsed TTL is a miss: callers rebuild instead of serving stale.
        // TTLs cap staleness for content whose inputs change without firing an
        // element event (e.g. schemamap's identity settings).
        return $output->isExpired(new DateTime()) ? null : $output;
    }

    /**
     * Stores (or replaces) the rendered content for a (site, type, contentKey).
     *
     * A transient DB failure here (deadlock, dropped connection during a
     * sitemap/llms rebuild) must not bubble up as a 500 to the visitor — the
     * cache write is best-effort, so we log and move on. The freshly rendered
     * content is still returned to the caller; only the persistence is lost.
     */
    public function set(int $siteId, RenderCacheType $type, ?string $contentKey, string $content, ?int $ttlSeconds = null): void
    {
        $record = RenderCacheRecord::findOne([
            'siteId' => $siteId,
            'type' => $type->value,
            'contentKey' => $contentKey,
        ]) ?? new RenderCacheRecord();

        $record->siteId = $siteId;
        $record->type = $type->value;
        $record->contentKey = $contentKey;
        $record->content = $content;
        $record->generatedAt = Db::now();
        $record->validUntil = $ttlSeconds !== null ? Db::future($ttlSeconds) : null;

        try {
            $record->save(false);
        } catch (\yii\db\Exception $e) {
            Craft::warning('Beacon: render cache write failed: ' . $e->getMessage(), 'beacon');
        }
    }

    /**
     * Drops the cached entry for a single (site, type, contentKey).
     */
    public function invalidate(int $siteId, RenderCacheType $type, ?string $contentKey = null): void
    {
        RenderCacheRecord::deleteAll([
            'siteId' => $siteId,
            'type' => $type->value,
            'contentKey' => $contentKey,
        ]);
    }

    /**
     * Read-or-rebuild with a per-cache-key mutex to suppress cache stampede.
     *
     * On a CDN-fronted site, a purge of `/sitemap.xml` can land 100+ concurrent
     * cold-cache requests at origin within a sub-second window. Without
     * coordination each one calls `$build()` (which re-materialises every
     * sitemap entry from MySQL) — bursting connection pools and pegging CPU.
     *
     * This wrapper:
     *   1. Returns a cached value immediately on hit.
     *   2. On miss, tries to acquire a Yii mutex keyed by `(type, siteId,
     *      contentKey)` with a 5-second wait window. The first holder
     *      rebuilds; concurrent callers block.
     *   3. Concurrent callers retry the cache read after acquiring — by then
     *      the first holder has populated it, so they skip the rebuild and
     *      return the cached value.
     *   4. If the mutex can't be acquired within the wait window (rare:
     *      unresponsive mutex backend) the caller falls back to running
     *      `$build()` itself. This degrades to the pre-mutex behaviour rather
     *      than throwing — sitemap serving must not 500 because the lock
     *      driver is misbehaving.
     *
     * @param callable():string $build Builder closure that returns the
     *   rendered content. Called at most once per (type, siteId, contentKey)
     *   under normal contention.
     */
    public function getOrRebuild(
        int $siteId,
        RenderCacheType $type,
        ?string $contentKey,
        callable $build,
        ?int $ttlSeconds = null,
    ): string {
        $cached = $this->get($siteId, $type, $contentKey);
        if ($cached !== null) {
            return $cached->content;
        }

        $mutex = Craft::$app->getMutex();
        $lockKey = sprintf('beacon-render-cache:%s:%d:%s', $type->value, $siteId, $contentKey ?? '_');
        $acquired = $mutex->acquire($lockKey, 5);

        try {
            if ($acquired) {
                $cached = $this->get($siteId, $type, $contentKey);
                if ($cached !== null) {
                    return $cached->content;
                }
            }
            $content = $build();
            $this->set($siteId, $type, $contentKey, $content, $ttlSeconds);
            return $content;
        } finally {
            if ($acquired) {
                $mutex->release($lockKey);
            }
        }
    }

    /**
     * Bulk-drops cached entries, optionally narrowed by site and/or type.
     * With no arguments, clears the entire render cache.
     */
    public function flush(?int $siteId = null, ?RenderCacheType $type = null): void
    {
        $where = array_filter([
            'siteId' => $siteId,
            'type' => $type?->value,
        ], static fn($v): bool => $v !== null);
        RenderCacheRecord::deleteAll($where);
    }

    /**
     * Invalidates every cached output that depends on a given section's content.
     * Sitemap hits also flush the derived schemamap cache for the same site.
     */
    public function invalidateForSection(string $sectionHandle): void
    {
        $entries = $this->getInvalidationMap()[$sectionHandle] ?? [];

        $sitemapSitesFlushed = [];
        foreach ($entries as $target) {
            if ($target['type'] === RenderCacheType::Sitemap) {
                $sid = $target['siteId'];
                if (isset($sitemapSitesFlushed[$sid])) {
                    continue;
                }

                $this->flush($sid, RenderCacheType::Sitemap);
                // schemamap.json derives from the same sitemap sections
                // (SchemamapService::buildMap reads getSitemap()->sections), so
                // it must be invalidated on the same content changes.
                $this->flush($sid, RenderCacheType::Schemamap);
                $sitemapSitesFlushed[$sid] = true;
                continue;
            }
            $this->invalidate($target['siteId'], $target['type'], $target['contentKey']);
        }
    }

    /**
     * @return array<string,list<RenderCacheInvalidationTarget>>
     */
    private function getInvalidationMap(): array
    {
        return $this->invalidationMap ??= $this->buildMap();
    }

    /**
     * @return array<string,list<RenderCacheInvalidationTarget>>
     */
    private function buildMap(): array
    {
        $map = [];
        /** @var list<SitemapSettingsRecord> $sitemapRecords */
        $sitemapRecords = SitemapSettingsRecord::find()->all();
        $this->appendRecordsToMap($map, $sitemapRecords, RenderCacheType::Sitemap);
        /** @var list<LlmsSettingsRecord> $llmsRecords */
        $llmsRecords = LlmsSettingsRecord::find()->all();
        $this->appendRecordsToMap($map, $llmsRecords, RenderCacheType::LlmsTxt);
        return $map;
    }

    /**
     * @param array<string,list<RenderCacheInvalidationTarget>> $map
     * @param list<SitemapSettingsRecord>|list<LlmsSettingsRecord> $records
     */
    private function appendRecordsToMap(array &$map, array $records, RenderCacheType $cacheType): void
    {
        foreach ($records as $record) {
            $sections = json_decode((string) $record->sections, true);
            if (!is_array($sections)) {
                continue;
            }
            foreach ($sections as $sectionHandle) {
                if (!is_string($sectionHandle)) {
                    continue;
                }
                $map[$sectionHandle][] = [
                    'siteId' => (int) $record->siteId,
                    'type' => $cacheType,
                    'contentKey' => null,
                ];
            }
        }
    }
}
