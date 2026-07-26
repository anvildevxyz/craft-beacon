<?php

namespace anvildev\beacon\migrations;

use craft\db\Migration;

/**
 * Adds the master on/off toggle for the Links (internal-link-graph) feature.
 */
class m260629_000001_links_feature_enabled extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%beacon_link_settings}}', 'enabled')) {
            $this->addColumn(
                '{{%beacon_link_settings}}',
                'enabled',
                $this->boolean()->notNull()->defaultValue(true)->after('id'),
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%beacon_link_settings}}', 'enabled')) {
            $this->dropColumn('{{%beacon_link_settings}}', 'enabled');
        }

        return true;
    }
}
