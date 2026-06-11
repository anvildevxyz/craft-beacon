<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $siteId
 * @property int $urlCount
 * @property string|null $firstUrl
 * @property int|null $statusCode
 * @property bool $succeeded
 * @property string|null $note
 * @property string $submittedAt
 */
class IndexNowSubmissionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_indexnow_submissions}}';
    }
}
