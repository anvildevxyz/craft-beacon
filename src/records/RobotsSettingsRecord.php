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
    public static function tableName(): string
    {
        return '{{%beacon_robots_settings}}';
    }
}
