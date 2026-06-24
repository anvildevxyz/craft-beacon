<?php

namespace anvildev\beacon\migrations;

use Craft;
use craft\db\Migration;

/**
 * Adds answer-engine visibility tracking: benchmark prompts, probe results, and
 * the per-site settings columns. Idempotent — safe on re-run and alongside a
 * fresh {@see Install}, which already creates these.
 */
class m260619_000002_ai_visibility_tracking extends Migration
{
    private const SETTINGS = '{{%beacon_settings}}';
    private const PROMPTS = '{{%beacon_benchmark_prompts}}';
    private const RESULTS = '{{%beacon_ai_visibility_results}}';

    public function safeUp(): bool
    {
        $this->addColumnIfMissing('aiVisibilityEnabled', $this->boolean()->notNull()->defaultValue(false));
        $this->addColumnIfMissing('aiVisibilityEngines', $this->text());
        $this->addColumnIfMissing('aiVisibilityCompetitorDomains', $this->text());
        $this->addColumnIfMissing('aiVisibilityMaxPerRun', $this->smallInteger()->unsigned()->notNull()->defaultValue(50));
        $this->addColumnIfMissing('aiVisibilityResultRetentionDays', $this->integer()->unsigned()->notNull()->defaultValue(365));
        $this->addColumnIfMissing('aiVisibilityCadence', $this->string(16)->notNull()->defaultValue('off'));

        if ($this->db->tableExists(self::PROMPTS) === false) {
            $this->createTable(self::PROMPTS, [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer()->notNull(),
                'prompt' => $this->text()->notNull(),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, self::PROMPTS, ['siteId']);
            $this->addForeignKey(null, self::PROMPTS, ['siteId'], '{{%sites}}', ['id'], 'CASCADE');
        }

        if ($this->db->tableExists(self::RESULTS) === false) {
            $this->createTable(self::RESULTS, [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer()->notNull(),
                'promptId' => $this->integer(),
                'promptText' => $this->text()->notNull(),
                'engine' => $this->string(64)->notNull(),
                'cited' => $this->boolean()->notNull()->defaultValue(false),
                'domainMentioned' => $this->boolean()->notNull()->defaultValue(false),
                'matchedUrls' => $this->text(),
                'competitorMentions' => $this->text(),
                'answerExcerpt' => $this->text(),
                'runAt' => $this->dateTime()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, self::RESULTS, ['siteId', 'runAt']);
            $this->addForeignKey(null, self::RESULTS, ['siteId'], '{{%sites}}', ['id'], 'CASCADE');
            $this->addForeignKey(null, self::RESULTS, ['promptId'], self::PROMPTS, ['id'], 'SET NULL');
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::RESULTS);
        $this->dropTableIfExists(self::PROMPTS);
        foreach ([
            'aiVisibilityEnabled',
            'aiVisibilityEngines',
            'aiVisibilityCompetitorDomains',
            'aiVisibilityMaxPerRun',
            'aiVisibilityResultRetentionDays',
            'aiVisibilityCadence',
        ] as $column) {
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
