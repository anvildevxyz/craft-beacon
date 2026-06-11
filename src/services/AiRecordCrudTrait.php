<?php

namespace anvildev\beacon\services;

use anvildev\beacon\helpers\Db;
use anvildev\beacon\records\AiBotRecord;
use anvildev\beacon\records\AiCrawlerRuleRecord;

/**
 * Shared record CRUD for the memoised AI bot/crawler services. Each consumer
 * keeps its own typed memo and supplies invalidateMemo(), which the write
 * helpers here call so changes are visible within the same request.
 */
trait AiRecordCrudTrait
{
    abstract protected function invalidateMemo(): void;

    /** @param class-string<AiBotRecord>|class-string<AiCrawlerRuleRecord> $recordClass */
    private function nextSortOrder(string $recordClass): int
    {
        return (int) ($recordClass::find()->max('[[sortOrder]]') ?? -1) + 1;
    }

    /** @param class-string<AiBotRecord>|class-string<AiCrawlerRuleRecord> $recordClass */
    private function setRecordEnabled(string $recordClass, int $id, bool $enabled): bool
    {
        $record = $recordClass::findOne($id);
        if ($record === null) {
            return false;
        }
        $record->enabled = $enabled;
        $record->dateUpdated = Db::now();
        $record->save(false);
        $this->invalidateMemo();
        return true;
    }

    /** @param class-string<AiBotRecord>|class-string<AiCrawlerRuleRecord> $recordClass */
    private function deleteRecordById(string $recordClass, int $id): void
    {
        $record = $recordClass::findOne($id);
        if ($record !== null) {
            $record->delete();
            $this->invalidateMemo();
        }
    }
}
