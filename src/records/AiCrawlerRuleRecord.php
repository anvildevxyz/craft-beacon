<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $botName
 * @property string $allowPaths
 * @property string $disallowPaths
 * @property int $sortOrder
 * @property bool $enabled
 */
class AiCrawlerRuleRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_ai_crawler_rules}}';
    }
}
