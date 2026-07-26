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
    use InvalidatesCacheTagTrait;

    public const CACHE_TAG = 'beacon_site_settings';

    public static function tableName(): string
    {
        return '{{%beacon_ads_settings}}';
    }
}
