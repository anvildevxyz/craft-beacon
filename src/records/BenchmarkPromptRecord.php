<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $siteId
 * @property string $prompt
 * @property bool $enabled
 */
class BenchmarkPromptRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_benchmark_prompts}}';
    }
}
