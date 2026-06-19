<?php

namespace anvildev\beacon\migrations;

use Craft;
use craft\db\Migration;

/**
 * Adds the per-site llms-full.txt token budget column to
 * `beacon_llms_settings`. Idempotent: the column is added only when missing,
 * so re-runs and fresh installs (which already get the column from
 * {@see Install}) are safe.
 */
class m260619_000005_llms_token_budget extends Migration
{
    private const TABLE = '{{%beacon_llms_settings}}';

    public function safeUp(): bool
    {
        if (!$this->columnExists('llmsFullTokenBudget')) {
            $this->addColumn(self::TABLE, 'llmsFullTokenBudget', $this->integer()->unsigned());
        }
        return true;
    }

    public function safeDown(): bool
    {
        if ($this->columnExists('llmsFullTokenBudget')) {
            $this->dropColumn(self::TABLE, 'llmsFullTokenBudget');
        }
        return true;
    }

    private function columnExists(string $column): bool
    {
        $schema = Craft::$app->getDb()->getTableSchema(self::TABLE);
        return $schema !== null && $schema->getColumn($column) !== null;
    }
}
