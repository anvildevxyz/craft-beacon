<?php

namespace anvildev\beacon\migrations;

use Craft;
use craft\db\Migration;

/**
 * Adds the AI-usage / content-licensing policy columns to the settings row.
 * Idempotent — safe on re-run and alongside a fresh {@see Install}, which
 * already creates these. Default `allow` keeps upgrades a no-op (no new
 * signals emitted until an operator opts in).
 */
class m260619_000003_ai_usage_policy extends Migration
{
    private const SETTINGS = '{{%beacon_settings}}';

    public function safeUp(): bool
    {
        $this->addColumnIfMissing('aiUsagePolicy', $this->string(20)->notNull()->defaultValue('allow'));
        $this->addColumnIfMissing('aiUsagePolicyUrl', $this->string(255));
        return true;
    }

    public function safeDown(): bool
    {
        foreach (['aiUsagePolicy', 'aiUsagePolicyUrl'] as $column) {
            if ($this->columnExists($column)) {
                $this->dropColumn(self::SETTINGS, $column);
            }
        }
        return true;
    }

    private function addColumnIfMissing(string $column, mixed $type): void
    {
        if (!$this->columnExists($column)) {
            $this->addColumn(self::SETTINGS, $column, $type);
        }
    }

    private function columnExists(string $column): bool
    {
        $schema = Craft::$app->getDb()->getTableSchema(self::SETTINGS);
        return $schema !== null && $schema->getColumn($column) !== null;
    }
}
