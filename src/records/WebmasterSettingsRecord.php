<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $siteId
 * @property string|null $indexNowKey
 */
class WebmasterSettingsRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_webmaster_settings}}';
    }
}
