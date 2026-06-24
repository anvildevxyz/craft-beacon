<?php

namespace anvildev\beacon\jobs;

use anvildev\beacon\helpers\links\KeywordExtractor;
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

        $links = Plugin::getInstance()?->links;
        if ($links === null) {
            Craft::warning('Beacon: Plugin instance unavailable, skipping batch index entry.', 'beacon');
            return false;
        }
        $settings = $links->getSettings();

        $section = $entry->getSection();
        if ($settings->enabledSections !== [] && ($section === null || !in_array($section->uid, $settings->enabledSections, true))) {
            return false;
        }

        // Collect content from this entry AND all its nested entries
        $allFieldContent = $this->collectAllFieldContent($entry, $links);
        $structured = $links->index->extractStructuredContent($entry->title ?? '', $allFieldContent);

        // Always scan links — even entries with no text content may contain
        // internal links needed for click-depth calculations (e.g. the homepage).
        $siteUrl = Craft::$app->getSites()->getSiteById($this->siteId)?->getBaseUrl() ?? '';
        $extractedLinks = $links->linkScan->extractLinksFromFields($allFieldContent, $siteUrl);
        $resolvedLinks = $this->resolveLinks($extractedLinks, $entryId, $siteUrl);

        $relationLinks = $this->collectEntryRelations($entry);
        foreach ($relationLinks as $rel) {
            if ($rel['targetElementId'] === $entryId) {
                continue;
            }
            $resolvedLinks[] = $rel;
        }

        $links->linkScan->saveLinks($entryId, $this->siteId, $resolvedLinks);

        if ($structured['body'] === '' && $structured['title'] === '') {
            return false;
        }

        $extractor = new KeywordExtractor(
            maxKeywords: $settings->maxKeywordsPerEntry,
            minLength: $settings->minKeywordLength,
            language: $settings->stopWordsLanguage,
        );
        $keywords = $extractor->extractStructured($structured);

        $existingHash = $links->index->getExistingHash($entryId, $this->siteId);
        if (!$links->index->shouldReindex($keywords, $existingHash)) {
            return false;
        }

        $links->index->saveIndex($entryId, $this->siteId, $keywords);

        $apiKey = \craft\helpers\App::parseEnv($settings->embeddingsApiKey);
        if ($settings->embeddingsEnabled && $apiKey !== '' && $apiKey !== false) {
            $baseUrl = \craft\helpers\App::parseEnv($settings->embeddingsBaseUrl);
            $text = $links->index->extractTextFromFields($allFieldContent);
            $embedding = $links->embedding->fetchEmbedding(
                $text,
                $settings->embeddingsModel,
                (string) $apiKey,
                is_string($baseUrl) ? $baseUrl : null,
            );
            if ($embedding !== null) {
                $links->embedding->saveEmbedding($entryId, $this->siteId, $embedding, $settings->embeddingsModel);
            }
        }

        $links->suggestions->invalidateCache($entryId, $this->siteId);

        return true;
    }
}
