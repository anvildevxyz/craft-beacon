<?php

namespace anvildev\beacon\helpers;

use anvildev\beacon\Plugin;
use Craft;
use yii\caching\TagDependency;

/**
 * Cross-request caching for the settings and registry rows Beacon reads on
 * every front-end request.
 *
 * Beacon's services already memoise these per request, but the first read still
 * costs a query — and, because they are ActiveRecord-backed, a schema lookup
 * with it. Caching the hydrated model instead of the row means the record class
 * is never touched on a hit, so the table introspection goes away too.
 *
 * Entries are dropped by tag when the underlying record is written — each
 * record class owns a `CACHE_TAG` and invalidates it from
 * `afterSave()`/`afterDelete()` — and expire on time as a backstop.
 */
final class RecordCache
{
    /**
     * Ceiling on how long a cached payload may survive without its tag being
     * invalidated.
     *
     * Tag invalidation reaches one cache instance, and Craft's default cache is
     * a per-server `FileCache`. On a multi-node deployment the node that
     * handles a CP save is the only one that hears about it, so without a TTL
     * the other nodes would serve the pre-save settings until their next
     * deploy. Five minutes bounds that divergence while still absorbing
     * essentially every front-end read.
     */
    private const DURATION = 300;

    /**
     * Returns the cached value for $key, building and storing it on a miss.
     *
     * @template T
     * @param callable():T $build
     * @param list<string> $tags
     * @return T
     */
    public static function remember(string $key, array $tags, callable $build): mixed
    {
        $cache = Craft::$app->getCache();
        $key = self::key($key);

        $cached = $cache->get($key);
        if ($cached !== false) {
            /** @var T $cached */
            return $cached;
        }

        // The tag versions are snapshotted *before* the build, not after it as
        // `getOrSet()` would: a write that lands while a cold rebuild is in
        // flight bumps the tag, and stamping the just-built (now stale) value
        // with the post-write version would mark it fresh and leave it that way
        // until some unrelated write happened to invalidate the tag again.
        $before = new TagDependency(['tags' => $tags]);
        $before->evaluateDependency($cache);

        /** @var T $value */
        $value = $build();

        $after = new TagDependency(['tags' => $tags]);
        $after->evaluateDependency($cache);

        // Raced with a write — serve what we built, but don't cache it.
        if ($after->data !== $before->data) {
            return $value;
        }

        $cache->set($key, $value, self::DURATION, $after);

        return $value;
    }

    /**
     * Namespaces cache keys by plugin version.
     *
     * The cached payloads are hydrated domain models, so an upgrade that
     * changes a model's constructor would otherwise unserialise objects that no
     * longer match the class. Versioning the key retires the whole namespace on
     * upgrade instead, without needing a migration to flush anything.
     */
    private static function key(string $key): string
    {
        return 'beacon.' . (Plugin::$plugin?->getVersion() ?? 'dev') . '.' . $key;
    }
}
