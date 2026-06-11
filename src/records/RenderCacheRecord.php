<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $siteId
 * @property string $type
 * @property string|null $contentKey
 * @property string $content
 * @property string $generatedAt
 * @property string|null $validUntil
 */
class RenderCacheRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_render_cache}}';
    }
}
