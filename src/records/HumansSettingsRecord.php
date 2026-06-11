<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $siteId
 * @property bool $enabled
 * @property string|null $body
 */
class HumansSettingsRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_humans_settings}}';
    }
}
