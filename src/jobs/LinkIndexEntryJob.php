<?php

namespace anvildev\beacon\jobs;

use anvildev\beacon\helpers\links\KeywordExtractor;
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

        // Skip nested entries (matrix blocks) — they're indexed via their parent
        if ($entry->ownerId !== null) {
            // Re-queue the root owner for indexing instead
            $rootId = $this->resolveRootOwnerId($entry);
            if ($rootId !== $this->entryId) {
                Craft::$app->getQueue()->push(new self([
                    'entryId' => $rootId,
                    'siteId' => $this->siteId,
                ]));
            }
            return;
        }

        $links = Plugin::getInstance()?->links;
        if ($links === null) {
            Craft::warning('Beacon: Plugin instance unavailable, skipping index job.', 'beacon');
            return;
        }
        $settings = $links->getSettings();

        $section = $entry->getSection();
        if ($settings->enabledSections !== [] && ($section === null || !in_array($section->uid, $settings->enabledSections, true))) {
            return;
        }

        $this->setProgress($queue, 0.1, 'Extracting content...');

        // Collect content from this entry AND all its nested entries
        $allFieldContent = $this->collectAllFieldContent($entry, $links);
        $structured = $links->index->extractStructuredContent($entry->title ?? '', $allFieldContent);

        $this->setProgress($queue, 0.5, 'Scanning links...');

        // Always scan links — even entries with no text content may contain
        // internal links needed for click-depth calculations (e.g. the homepage).
        $siteUrl = Craft::$app->getSites()->getSiteById($this->siteId)?->getBaseUrl() ?? '';
        $extractedLinks = $links->linkScan->extractLinksFromFields($allFieldContent, $siteUrl);
        $resolvedLinks = $this->resolveLinks($extractedLinks, $this->entryId, $siteUrl);

        // Also capture entry relations (Entries fields, Hyper link fields) — these
        // represent navigable links even though they aren't <a> tags in HTML.
        $relationLinks = $this->collectEntryRelations($entry);
        foreach ($relationLinks as $rel) {
            // Skip self-links and duplicates
            if ($rel['targetElementId'] === $this->entryId) {
                continue;
            }
            $resolvedLinks[] = $rel;
        }

        $links->linkScan->saveLinks($this->entryId, $this->siteId, $resolvedLinks);

        if ($structured['body'] === '' && $structured['title'] === '') {
            $this->setProgress($queue, 1.0, 'Done (links only).');
            return;
        }

        $this->setProgress($queue, 0.3, 'Extracting keywords...');

        $extractor = new KeywordExtractor(
            maxKeywords: $settings->maxKeywordsPerEntry,
            minLength: $settings->minKeywordLength,
            language: $settings->stopWordsLanguage,
        );
        $keywords = $extractor->extractStructured($structured);

        $existingHash = $links->index->getExistingHash($this->entryId, $this->siteId);
        if (!$links->index->shouldReindex($keywords, $existingHash)) {
            $this->setProgress($queue, 1.0, 'No changes detected.');
            return;
        }

        $links->index->saveIndex($this->entryId, $this->siteId, $keywords);

        $this->setProgress($queue, 0.7, 'Processing embeddings...');

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
                $links->embedding->saveEmbedding($this->entryId, $this->siteId, $embedding, $settings->embeddingsModel);
            }
        }

        $links->suggestions->invalidateCache($this->entryId, $this->siteId);
        $links->reports->invalidateCache();

        $this->setProgress($queue, 1.0, 'Done.');
    }

    protected function defaultDescription(): string
    {
        return "Indexing entry {$this->entryId} for Beacon";
    }
}
