<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $userAgentPattern
 * @property bool $enabled
 * @property string $source
 * @property string|null $purpose
 * @property int $sortOrder
 */
class AiBotRecord extends ActiveRecord
{
    use InvalidatesCacheTagTrait;

    public const CACHE_TAG = 'beacon_ai_bots';

    public static function tableName(): string
    {
        return '{{%beacon_ai_bots}}';
    }
}
