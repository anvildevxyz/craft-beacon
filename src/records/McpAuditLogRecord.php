<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int|null $tokenId
 * @property int|null $userId
 * @property string $agentLabel
 * @property string $tool
 * @property string|null $arguments
 * @property bool $success
 * @property string|null $error
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class McpAuditLogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_mcp_audit_log}}';
    }
}
