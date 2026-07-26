<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $elementId
 * @property int $siteId
 * @property string $embedding
 * @property string $model
 * @property string $dateIndexed
 */
class LinkEmbeddingRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_link_embeddings}}';
    }
}
