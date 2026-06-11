<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $siteId
 * @property int $elementId
 * @property string|null $markdown
 * @property string|null $hash
 * @property string|null $dateGenerated
 * @property string|null $dateRequested
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class GeoMarkdownRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_geo_markdown}}';
    }
}
