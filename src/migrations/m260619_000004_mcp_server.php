<?php

namespace anvildev\beacon\migrations;

use Craft;
use craft\db\Migration;

/**
 * Adds the MCP server: a `mcpEnabled` settings toggle (default off), an API
 * token table, and a write-action audit log. Idempotent — safe on re-run and
 * alongside a fresh {@see Install}, which already creates these.
 */
class m260619_000004_mcp_server extends Migration
{
    private const SETTINGS = '{{%beacon_settings}}';
    private const TOKENS = '{{%beacon_mcp_tokens}}';
    private const AUDIT = '{{%beacon_mcp_audit_log}}';

    public function safeUp(): bool
    {
        if (!$this->columnExists('mcpEnabled')) {
            $this->addColumn(self::SETTINGS, 'mcpEnabled', $this->boolean()->notNull()->defaultValue(false));
        }

        if ($this->db->tableExists(self::TOKENS) === false) {
            $this->createTable(self::TOKENS, [
                'id' => $this->primaryKey(),
                'name' => $this->string()->notNull(),
                'userId' => $this->integer()->notNull(),
                'tokenHash' => $this->string(255)->notNull(),
                'tokenPrefix' => $this->string(12)->notNull(),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'lastUsedAt' => $this->dateTime(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, self::TOKENS, ['tokenHash'], true);
            $this->createIndex(null, self::TOKENS, ['userId']);
            $this->addForeignKey(null, self::TOKENS, ['userId'], '{{%users}}', ['id'], 'CASCADE', null);
        }

        if ($this->db->tableExists(self::AUDIT) === false) {
            $this->createTable(self::AUDIT, [
                'id' => $this->primaryKey(),
                'tokenId' => $this->integer(),
                'userId' => $this->integer(),
                'agentLabel' => $this->string()->notNull()->defaultValue(''),
                'tool' => $this->string()->notNull(),
                'arguments' => $this->text(),
                'success' => $this->boolean()->notNull()->defaultValue(true),
                'error' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, self::AUDIT, ['dateCreated']);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::AUDIT);
        $this->dropTableIfExists(self::TOKENS);
        if ($this->columnExists('mcpEnabled')) {
            $this->dropColumn(self::SETTINGS, 'mcpEnabled');
        }
        return true;
    }

    private function columnExists(string $column): bool
    {
        $schema = Craft::$app->getDb()->getTableSchema(self::SETTINGS);
        return $schema !== null && $schema->getColumn($column) !== null;
    }
}
