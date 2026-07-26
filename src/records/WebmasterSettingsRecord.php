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
    use InvalidatesCacheTagTrait;

    public const CACHE_TAG = 'beacon_site_settings';

    public static function tableName(): string
    {
        return '{{%beacon_webmaster_settings}}';
    }
}
