<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $sourceElementId
 * @property int $sourceSiteId
 * @property int|null $targetElementId
 * @property int|null $targetSiteId
 * @property string|null $targetElementType
 * @property string $fieldHandle
 * @property string|null $anchorText
 * @property bool $isExternal
 * @property string|null $targetUrl
 * @property int|null $httpStatus
 * @property string|null $httpCheckedAt
 * @property bool $ignored
 */
class LinkRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_links}}';
    }
}
