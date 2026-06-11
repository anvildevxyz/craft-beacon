<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $siteId
 * @property bool $enabled
 * @property int|null $assetId
 * @property string|null $body
 */
class AdsSettingsRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_ads_settings}}';
    }
}
