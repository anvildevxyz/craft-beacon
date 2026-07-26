<?php

namespace anvildev\beacon\records;

use anvildev\beacon\helpers\AfterCommit;
use Craft;
use yii\caching\TagDependency;

/**
 * Drops the implementing record's cache tag whenever a row is written.
 *
 * Paired with {@see \anvildev\beacon\helpers\RecordCache}, which reads through
 * that tag. Implementers declare `public const CACHE_TAG`.
 *
 * Covers ActiveRecord writes only. Bulk operations that bypass the record
 * lifecycle — `updateAll()`, `deleteAll()`, raw `Command` updates — must
 * invalidate explicitly; see {@see self::invalidateCacheTag()}.
 */
trait InvalidatesCacheTagTrait
{
    /**
     * @param bool $insert
     * @param array<string, mixed> $changedAttributes
     */
    public function afterSave($insert, $changedAttributes): void
    {
        parent::afterSave($insert, $changedAttributes);
        static::invalidateCacheTag();
    }

    public function afterDelete(): void
    {
        parent::afterDelete();
        static::invalidateCacheTag();
    }

    /**
     * Invalidates this record's cache tag. Public so callers that write around
     * the record lifecycle can keep the cache honest.
     *
     * Fires twice when a transaction is open: once now, so nothing in this
     * request keeps reading the pre-write value, and once after the commit.
     * The second pass is what closes the window in which a concurrent request
     * cannot yet see the new row, re-runs the query, and re-caches the old
     * answer against the tag version this call just bumped.
     */
    public static function invalidateCacheTag(): void
    {
        $tag = static::CACHE_TAG;

        TagDependency::invalidate(Craft::$app->getCache(), $tag);

        AfterCommit::run('invalidate:' . $tag, static function() use ($tag): void {
            TagDependency::invalidate(Craft::$app->getCache(), $tag);
        });
    }
}
