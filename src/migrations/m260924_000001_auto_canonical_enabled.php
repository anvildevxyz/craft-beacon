<?php

namespace anvildev\beacon\migrations;

use craft\db\Migration;

/**
 * Adds the opt-in toggle for self-referencing canonicals on entries that
 * leave the SEO field's canonical blank.
 */
class m260924_000001_auto_canonical_enabled extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%beacon_settings}}', 'autoCanonicalEnabled')) {
            $this->addColumn(
                '{{%beacon_settings}}',
                'autoCanonicalEnabled',
                $this->boolean()->notNull()->defaultValue(false)->after('titleTemplate'),
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%beacon_settings}}', 'autoCanonicalEnabled')) {
            $this->dropColumn('{{%beacon_settings}}', 'autoCanonicalEnabled');
        }

        return true;
    }
}
