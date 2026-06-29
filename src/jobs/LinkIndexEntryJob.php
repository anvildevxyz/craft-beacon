<?php

namespace anvildev\beacon\jobs;

use anvildev\beacon\jobs\traits\IndexesEntries;
use anvildev\beacon\Plugin;
use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;

class LinkIndexEntryJob extends BaseJob
{
    use IndexesEntries;

    public int $entryId;
    public int $siteId;

    public function getTtr(): int
    {
        return 60;
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
        /** @var Entry|null $entry */
        $entry = Entry::find()->id($this->entryId)->siteId($this->siteId)->status(null)->one();
        if ($entry === null) {
            Craft::warning("Beacon: Entry {$this->entryId} not found, skipping index.", 'beacon');
            return;
        }

        // Skip nested entries (matrix blocks) — re-queue the root owner instead.
        if ($entry->ownerId !== null) {
            $rootId = $this->resolveRootOwnerId($entry);
            if ($rootId !== $this->entryId) {
                Craft::$app->getQueue()->push(new self([
                    'entryId' => $rootId,
                    'siteId' => $this->siteId,
                ]));
            }
            return;
        }

        $indexed = $this->indexRootEntry($entry, $this->entryId, function(float $pct, string $message) use ($queue): void {
            $this->setProgress($queue, $pct, $message);
        });

        if ($indexed) {
            Plugin::getInstance()?->links->reports->invalidateCache();
            $this->setProgress($queue, 1.0, 'Done.');
        }
    }

    protected function defaultDescription(): string
    {
        return "Indexing entry {$this->entryId} for Beacon";
    }
}
