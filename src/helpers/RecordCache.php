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
 * Entries never expire on time; they are dropped by tag when the underlying
 * record is written. Each record class owns a `CACHE_TAG` and invalidates it
 * from `afterSave()`/`afterDelete()`.
 */
final class RecordCache
{
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
        /** @var T $value */
        $value = Craft::$app->getCache()->getOrSet(
            self::key($key),
            $build,
            null,
            new TagDependency(['tags' => $tags]),
        );

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
