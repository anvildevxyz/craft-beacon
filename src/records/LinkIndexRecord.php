<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $elementId
 * @property int $siteId
 * @property string $keywords
 * @property string $keywordHash
 * @property string $dateIndexed
 */
class LinkIndexRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_link_index}}';
    }
}
