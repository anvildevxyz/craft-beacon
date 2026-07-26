<?php

namespace anvildev\beacon\jobs;

use anvildev\beacon\jobs\traits\IndexesEntries;
use anvildev\beacon\Plugin;
use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;

class LinkBatchIndexJob extends BaseJob
{
    use IndexesEntries;

    /** @var int[] */
    public array $entryIds = [];

    public int $siteId;

    public function getTtr(): int
    {
        return min(600, max(60, count($this->entryIds) * 5));
    }

    /**
     * @param int $attempt
     * @param \Throwable $error
     */
    public function canRetry($attempt, $error): bool
    {
        return $attempt < 3;
    }

    public function execute($queue): void
    {
        $total = count($this->entryIds);
        $anyIndexed = false;

        foreach ($this->entryIds as $index => $entryId) {
            $this->setProgress($queue, $total > 0 ? $index / $total : 1, sprintf('Processing entry %d/%d', $index + 1, $total));

            try {
                if ($this->indexEntry($entryId)) {
                    $anyIndexed = true;
                }
            } catch (\Throwable $e) {
                Craft::warning("Beacon: Failed to index entry {$entryId} in batch: {$e->getMessage()}", 'beacon');
            }
        }

        if ($anyIndexed) {
            $links = Plugin::getInstance()?->links;
            if ($links !== null) {
                $links->reports->invalidateCache();
            }
        }

        $this->setProgress($queue, 1.0, 'Done.');
    }

    protected function defaultDescription(): string
    {
        $count = count($this->entryIds);

        return "Batch indexing {$count} entries for Beacon";
    }

    /**
     * Index a single entry within the batch.
     *
     * @return bool Whether the entry was actually indexed (content changed).
     */
    private function indexEntry(int $entryId): bool
    {
        /** @var Entry|null $entry */
        $entry = Entry::find()->id($entryId)->siteId($this->siteId)->status(null)->one();
        if ($entry === null) {
            Craft::warning("Beacon: Entry {$entryId} not found, skipping index.", 'beacon');
            return false;
        }

        // Skip nested entries (matrix blocks) -- they're indexed via their parent
        if ($entry->ownerId !== null) {
            return false;
        }

        return $this->indexRootEntry($entry, $entryId);
    }
}
