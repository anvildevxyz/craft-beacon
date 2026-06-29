<?php

namespace anvildev\beacon\services\links;

use anvildev\beacon\events\LinkIndexEntryEvent;
use anvildev\beacon\helpers\links\KeywordExtractor;
use anvildev\beacon\records\LinkEmbeddingRecord;
use anvildev\beacon\records\LinkIndexRecord;
use craft\base\Component;
use craft\elements\Entry;
use craft\errors\InvalidFieldException;
use yii\base\Event;

class IndexService extends Component
{
    public const EVENT_BEFORE_INDEX_ENTRY = 'beforeIndexEntry';
    public const EVENT_AFTER_INDEX_ENTRY = 'afterIndexEntry';

    /** @param array<string, string> $fields */
    public function extractTextFromFields(array $fields): string
    {
        if ($fields === []) {
            return '';
        }
        $parts = [];
        foreach ($fields as $content) {
            $text = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim($text);
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        return implode(' ', $parts);
    }

    /** @param array<string, float> $keywords */
    public function shouldReindex(array $keywords, ?string $existingHash): bool
    {
        if ($existingHash === null) {
            return true;
        }

        return KeywordExtractor::hashWeighted($keywords) !== $existingHash;
    }

    /** @param array<string, float> $keywords */
    public function saveIndex(int $elementId, int $siteId, array $keywords): void
    {
        $beforeEvent = new LinkIndexEntryEvent();
        $beforeEvent->elementId = $elementId;
        $beforeEvent->siteId = $siteId;
        $beforeEvent->keywords = $keywords;
        Event::trigger(self::class, self::EVENT_BEFORE_INDEX_ENTRY, $beforeEvent);

        $hash = KeywordExtractor::hashWeighted($keywords);
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        /** @var LinkIndexRecord|null $record */
        $record = LinkIndexRecord::find()->where(['elementId' => $elementId, 'siteId' => $siteId])->one();
        if ($record === null) {
            $record = new LinkIndexRecord();
            $record->elementId = $elementId;
            $record->siteId = $siteId;
        }
        $record->keywords = json_encode($keywords, JSON_THROW_ON_ERROR);
        $record->keywordHash = $hash;
        $record->dateIndexed = $now;
        $record->save();

        $afterEvent = new LinkIndexEntryEvent();
        $afterEvent->elementId = $elementId;
        $afterEvent->siteId = $siteId;
        $afterEvent->keywords = $keywords;
        Event::trigger(self::class, self::EVENT_AFTER_INDEX_ENTRY, $afterEvent);
    }

    /**
     * Extract structured content from HTML fields, separating headings from body text.
     *
     * @param array<string, string> $fields
     * @return array{title: string, headings: list<string>, body: string}
     */
    public function extractStructuredContent(string $title, array $fields): array
    {
        $headings = [];
        $bodyParts = [];

        foreach ($fields as $content) {
            // Extract h1-h3 headings before stripping tags
            if (preg_match_all('/<h[1-3][^>]*>(.*?)<\/h[1-3]>/is', $content, $matches)) {
                foreach ($matches[1] as $heading) {
                    $heading = trim(strip_tags($heading));
                    if ($heading !== '') {
                        $headings[] = $heading;
                    }
                }
            }

            // Strip all tags for body text
            $text = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim($text);
            if ($text !== '') {
                $bodyParts[] = $text;
            }
        }

        return [
            'title' => $title,
            'headings' => $headings,
            'body' => implode(' ', $bodyParts),
        ];
    }

    public function getExistingHash(int $elementId, int $siteId): ?string
    {
        /** @var LinkIndexRecord|null $record */
        $record = LinkIndexRecord::find()->where(['elementId' => $elementId, 'siteId' => $siteId])->one();
        return $record?->keywordHash;
    }

    /** @return array<int, array<string, float>> */
    public function loadCorpus(int $siteId): array
    {
        $corpus = [];
        $query = LinkIndexRecord::find()->where(['siteId' => $siteId]);
        foreach ($query->batch(500) as $batch) {
            foreach ($batch as $record) {
                /** @var LinkIndexRecord $record */
                $decoded = json_decode($record->keywords, true) ?? [];
                // Backward compat: convert old flat list format to weighted format
                if ($decoded !== [] && array_is_list($decoded)) {
                    $decoded = array_fill_keys($decoded, 1.0);
                }
                $corpus[$record->elementId] = $decoded;
            }
        }

        return $corpus;
    }

    /**
     * Delete all index and embedding rows for the given element across ALL sites.
     * Called from the Entry::EVENT_AFTER_DELETE handler when an element is fully removed
     * from Craft (including all its site localizations), so cross-site deletion is intentional.
     *
     * @param int $elementId The element ID whose rows should be removed from every site.
     */
    public function deleteByElementId(int $elementId): void
    {
        LinkIndexRecord::deleteAll(['elementId' => $elementId]);
        LinkEmbeddingRecord::deleteAll(['elementId' => $elementId]);
    }

    public function clearAll(): void
    {
        LinkIndexRecord::deleteAll();
        LinkEmbeddingRecord::deleteAll();
    }

    public function countIndexed(int $siteId): int
    {
        return (int) LinkIndexRecord::find()->where(['siteId' => $siteId])->count();
    }

    /**
     * @param string[] $fieldHandles
     * @return array<string, string>
     */
    public function getEntryFieldContent(Entry $entry, array $fieldHandles): array
    {
        $fields = [];
        foreach ($fieldHandles as $handle) {
            try {
                $value = $entry->getFieldValue($handle);
                if (is_string($value) && $value !== '') {
                    $fields[$handle] = $value;
                } elseif (is_object($value) && method_exists($value, '__toString')) {
                    $rendered = (string) $value;
                    if ($rendered !== '') {
                        $fields[$handle] = $rendered;
                    }
                }
            } catch (InvalidFieldException $e) {
                \Craft::warning("Beacon: Invalid field handle '{$handle}' on entry {$entry->id}: {$e->getMessage()}", 'beacon');
                continue;
            }
        }
        return $fields;
    }
}
