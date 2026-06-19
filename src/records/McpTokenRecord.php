<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property int $userId
 * @property string $tokenHash
 * @property string $tokenPrefix
 * @property bool $enabled
 * @property string|null $lastUsedAt
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class McpTokenRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_mcp_tokens}}';
    }
}
