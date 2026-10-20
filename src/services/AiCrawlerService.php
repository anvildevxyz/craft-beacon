<?php

namespace anvildev\beacon\services;

use anvildev\beacon\helpers\Db;
use anvildev\beacon\helpers\Json;
use anvildev\beacon\models\AiCrawlerRule;
use anvildev\beacon\records\AiCrawlerRuleRecord;
use yii\base\Component;

class AiCrawlerService extends Component
{
    use AiRecordCrudTrait;

    /**
     * Per-request memoisation — `getEnabledRules()` is the robots.txt hot
     * path (called from `RobotsController::actionIndex()`). Write paths
     * below invalidate the memo so changes are visible within the request.
     *
     * @var list<AiCrawlerRule>|null
     */
    private ?array $enabledMemo = null;

    /** @var list<AiCrawlerRule>|null */
    private ?array $allMemo = null;

    /** @return list<AiCrawlerRule> */
    public function getEnabledRules(): array
    {
        if ($this->enabledMemo !== null) {
            return $this->enabledMemo;
        }
        /** @var list<AiCrawlerRuleRecord> $records */
        $records = AiCrawlerRuleRecord::find()
            ->where(['enabled' => true])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();
        return $this->enabledMemo = array_map(fn(AiCrawlerRuleRecord $r) => $this->toModel($r), $records);
    }

    /** @return list<AiCrawlerRule> */
    public function getAllRules(): array
    {
        if ($this->allMemo !== null) {
            return $this->allMemo;
        }
        /** @var list<AiCrawlerRuleRecord> $records */
        $records = AiCrawlerRuleRecord::find()
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();
        return $this->allMemo = array_map(fn(AiCrawlerRuleRecord $r) => $this->toModel($r), $records);
    }

    private function invalidateMemo(): void
    {
        $this->enabledMemo = null;
        $this->allMemo = null;
    }

    public function findRule(int $id): ?AiCrawlerRule
    {
        $record = AiCrawlerRuleRecord::findOne($id);
        return $record ? $this->toModel($record) : null;
    }

    /**
     * @param list<string> $allowPaths
     * @param list<string> $disallowPaths
     */
    public function saveRule(?int $id, string $botName, array $allowPaths, array $disallowPaths, bool $enabled): AiCrawlerRule
    {
        $record = ($id ? AiCrawlerRuleRecord::findOne($id) : null) ?? new AiCrawlerRuleRecord();
        $record->botName = $botName;
        $record->allowPaths = Json::encode($allowPaths);
        $record->disallowPaths = Json::encode($disallowPaths);
        $record->enabled = $enabled;

        if ($record->isNewRecord) {
            $record->sortOrder = $this->nextSortOrder(AiCrawlerRuleRecord::class);
            $record->dateCreated = Db::now();
        }
        $record->dateUpdated = Db::now();
        $record->save(false);
        $this->invalidateMemo();

        return $this->toModel($record);
    }

    public function deleteRule(int $id): void
    {
        $this->deleteRecordById(AiCrawlerRuleRecord::class, $id);
    }

    /**
     * Bulk-applies an allow/disallow policy to every name in $botNames,
     * upserting one rule per name (matched against `AiCrawlerRule::$botName`).
     * An allowed bot gets an explicit no-restrictions rule (rather than just
     * deleting any existing rule) so the policy is visible and editable
     * afterward, not inferred from a rule's absence. A disallowed bot's rule
     * disallows `/`, blocking it site-wide.
     *
     * @param list<string> $botNames
     */
    public function applyPolicyToBots(array $botNames, bool $disallow): int
    {
        if ($botNames === []) {
            return 0;
        }

        /** @var array<string, AiCrawlerRuleRecord> $existingByName */
        $existingByName = AiCrawlerRuleRecord::find()
            ->where(['botName' => $botNames])
            ->indexBy('botName')
            ->all();

        $now = Db::now();
        $nextSortOrder = $this->nextSortOrder(AiCrawlerRuleRecord::class);
        $count = 0;

        foreach ($botNames as $botName) {
            $record = $existingByName[$botName] ?? new AiCrawlerRuleRecord();
            $record->botName = $botName;
            $record->allowPaths = Json::encode([]);
            $record->disallowPaths = Json::encode($disallow ? ['/'] : []);
            $record->enabled = true;
            if ($record->isNewRecord) {
                $record->sortOrder = $nextSortOrder++;
                $record->dateCreated = $now;
            }
            $record->dateUpdated = $now;
            $record->save(false);
            $count++;
        }

        $this->invalidateMemo();

        return $count;
    }

    public function setRuleEnabled(int $id, bool $enabled): bool
    {
        return $this->setRecordEnabled(AiCrawlerRuleRecord::class, $id, $enabled);
    }

    private function toModel(AiCrawlerRuleRecord $r): AiCrawlerRule
    {
        return new AiCrawlerRule(
            id: (int) $r->id,
            botName: (string) $r->botName,
            allowPaths: Json::decodeStringList($r->allowPaths),
            disallowPaths: Json::decodeStringList($r->disallowPaths),
            sortOrder: (int) $r->sortOrder,
            enabled: (bool) $r->enabled,
        );
    }
}
