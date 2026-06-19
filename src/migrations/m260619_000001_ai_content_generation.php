<?php

namespace anvildev\beacon\migrations;

use Craft;
use craft\db\Migration;

/**
 * Adds the AI content-generation provider settings to `beacon_settings`.
 * Idempotent: each column is added only when missing, so re-runs and
 * fresh installs (which already get the columns from {@see Install}) are safe.
 */
class m260619_000001_ai_content_generation extends Migration
{
    private const TABLE = '{{%beacon_settings}}';

    public function safeUp(): bool
    {
        $this->addColumnIfMissing('aiEnabled', $this->boolean()->notNull()->defaultValue(false));
        $this->addColumnIfMissing('aiProvider', $this->string(32)->notNull()->defaultValue('anthropic'));
        $this->addColumnIfMissing('aiModel', $this->string(128)->notNull()->defaultValue(''));
        $this->addColumnIfMissing('aiApiKey', $this->string(512));
        $this->addColumnIfMissing('aiBaseUrl', $this->string(255));
        return true;
    }

    public function safeDown(): bool
    {
        foreach (['aiEnabled', 'aiProvider', 'aiModel', 'aiApiKey', 'aiBaseUrl'] as $column) {
            if ($this->columnExists($column)) {
                $this->dropColumn(self::TABLE, $column);
            }
        }
        return true;
    }

    private function addColumnIfMissing(string $column, mixed $type): void
    {
        if (!$this->columnExists($column)) {
            $this->addColumn(self::TABLE, $column, $type);
        }
    }

    private function columnExists(string $column): bool
    {
        $schema = Craft::$app->getDb()->getTableSchema(self::TABLE);
        return $schema !== null && $schema->getColumn($column) !== null;
    }
}
