<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $userAgentPattern
 * @property bool $enabled
 * @property string $source
 * @property int $sortOrder
 */
class AiBotRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_ai_bots}}';
    }
}
