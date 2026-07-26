<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $entryTypeHandle
 * @property string $schemaType
 * @property string $mapping
 * @property int $sortOrder
 * @property bool $enabled
 */
class SchemaRecord extends ActiveRecord
{
    use InvalidatesCacheTagTrait;

    public const CACHE_TAG = 'beacon_schemas';

    public static function tableName(): string
    {
        return '{{%beacon_schemas}}';
    }
}
