<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $siteId
 * @property string $uri
 * @property int $hits
 * @property string $firstSeen
 * @property string $lastSeen
 * @property string|null $referer
 * @property bool $handled
 */
class Redirect404LogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_redirect_404_log}}';
    }
}
