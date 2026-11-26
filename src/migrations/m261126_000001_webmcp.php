<?php

namespace anvildev\beacon\migrations;

use craft\db\Migration;

/**
 * Adds the browser-side AI-agent tools script (issue #59): a per-site
 * `beacon_webmcp_settings` table with an `enabled` toggle, default off.
 * Idempotent — safe on re-run and alongside a fresh {@see Install}, which
 * already creates this table.
 */
class m261126_000001_webmcp extends Migration
{
    private const TABLE = '{{%beacon_webmcp_settings}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE)) {
            return true;
        }

        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull()->unique(),
            'enabled' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->addForeignKey(null, self::TABLE, ['siteId'], '{{%sites}}', ['id'], 'CASCADE');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE);
        return true;
    }
}
