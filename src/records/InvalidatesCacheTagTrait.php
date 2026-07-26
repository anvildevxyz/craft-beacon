<?php

namespace anvildev\beacon\records;

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
     */
    public static function invalidateCacheTag(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), static::CACHE_TAG);
    }
}
