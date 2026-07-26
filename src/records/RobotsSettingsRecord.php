<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $siteId
 * @property string $sitemapUrl
 * @property string $userAgentRules
 */
class RobotsSettingsRecord extends ActiveRecord
{
    use InvalidatesCacheTagTrait;

    public const CACHE_TAG = 'beacon_site_settings';

    public static function tableName(): string
    {
        return '{{%beacon_robots_settings}}';
    }
}
