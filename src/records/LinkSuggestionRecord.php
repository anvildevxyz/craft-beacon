<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $sourceElementId
 * @property int $targetElementId
 * @property int $siteId
 * @property string $status
 * @property float $score
 */
class LinkSuggestionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_link_suggestions}}';
    }
}
