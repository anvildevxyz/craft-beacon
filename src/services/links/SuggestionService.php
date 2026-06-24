<?php

namespace anvildev\beacon\services\links;

use anvildev\beacon\events\LinkSuggestionsGeneratedEvent;
use anvildev\beacon\helpers\links\PorterStemmer;
use anvildev\beacon\helpers\links\SentenceSplitter;
use anvildev\beacon\Plugin;
use anvildev\beacon\records\LinkSuggestionRecord;
use anvildev\beacon\types\ScoreTypes;
use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\errors\InvalidFieldException;
use craft\helpers\Db;
use yii\base\Event;
use yii\caching\TagDependency;

/** @phpstan-import-type ScoreRows from ScoreTypes */
class SuggestionService extends Component
{
    public const EVENT_SUGGESTIONS_GENERATED = 'suggestionsGenerated';

    /**
     * @param ScoreRows $results
     * @return ScoreRows
     */
    public function filterByMinScore(array $results, float $minScore): array
    {
        return array_values(array_filter($results, fn(array $r) => $r['score'] >= $minScore));
    }

    /**
     * @param ScoreRows $results
     * @return ScoreRows
     */
    public function limitResults(array $results, int $max): array
    {
        return array_slice($results, 0, $max);
    }

    /**
     * @param ScoreRows $keywordResults
     * @param ScoreRows $embeddingResults
     * @return ScoreRows
     */
    public function mergeResults(array $keywordResults, array $embeddingResults): array
    {
        $merged = [];
        foreach ($keywordResults as $result) {
            $merged[$result['elementId']] = $result;
        }
        foreach ($embeddingResults as $result) {
            $id = $result['elementId'];
            if (!isset($merged[$id]) || $result['score'] > $merged[$id]['score']) {
                $merged[$id] = $result;
            }
        }
        $merged = array_values($merged);
        usort($merged, fn(array $a, array $b) => $b['score'] <=> $a['score']);
        return $merged;
    }

    /**
     * @param callable(): array<mixed> $compute
     * @return array<mixed>
     */
    public function getCachedOrCompute(int $elementId, int $siteId, int $duration, callable $compute): array
    {
        $cache = Craft::$app->getCache();
        $key = "beacon_suggestions_{$elementId}_{$siteId}";
        $dependency = new TagDependency(['tags' => ["beacon_index_{$elementId}", "beacon_index_site_{$siteId}"]]);
        $suggestions = $cache->getOrSet($key, function() use ($elementId, $siteId, $compute): array {
            $results = $compute();

            $event = new LinkSuggestionsGeneratedEvent();
            $event->sourceElementId = $elementId;
            $event->siteId = $siteId;
            $event->suggestions = $results;
            Event::trigger(self::class, self::EVENT_SUGGESTIONS_GENERATED, $event);

            return $results;
        }, $duration, $dependency);

        return $suggestions;
    }

    public function invalidateCache(int $elementId, int $siteId): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), "beacon_index_{$elementId}");
        TagDependency::invalidate(Craft::$app->getCache(), "beacon_index_site_{$siteId}");
    }

    /**
     * Get element IDs that have already been accepted or dismissed for a source entry.
     *
     * @return int[]
     */
    public function getInteractedElementIds(int $sourceElementId, int $siteId): array
    {
        return LinkSuggestionRecord::find()
            ->where(['sourceElementId' => $sourceElementId, 'siteId' => $siteId])
            ->andWhere(['in', 'status', ['accepted', 'dismissed']])
            ->select('targetElementId')
            ->distinct()
            ->column();
    }

    public function recordInteraction(int $sourceElementId, int $targetElementId, int $siteId, string $status, float $score): void
    {
        // Single atomic upsert against the unique
        // (sourceElementId, targetElementId, siteId) index — no check-then-insert
        // race window and no triplicated update logic.
        Db::upsert(LinkSuggestionRecord::tableName(), [
            'sourceElementId' => $sourceElementId,
            'targetElementId' => $targetElementId,
            'siteId' => $siteId,
            'status' => $status,
            'score' => $score,
        ], [
            'status' => $status,
            'score' => $score,
        ]);

        // Invalidate suggestion + reports cache so dashboard/acceptance rate updates
        $this->invalidateCache($sourceElementId, $siteId);
        $links = Plugin::getInstance()?->links;
        if ($links !== null) {
            $links->reports->invalidateCache();
        }
    }

    /**
     * Find the best sentence and anchor text for a link insertion.
     *
     * Scores sentences by IDF-weighted keyword overlap with the target.
     * Returns the winning sentence and the best anchor keyword within it
     * (prefers bigrams over unigrams for more specific anchor text).
     *
     * @param list<string> $sentences
     * @param array<string, float> $targetKeywords stemmed keyword => weight
     * @param array<string, float> $idf term => IDF value
     * @return array{sentence: string, anchor: string}|null
     */
    public function findBestAnchor(array $sentences, array $targetKeywords, array $idf): ?array
    {
        $bestSentence = null;
        $bestSentenceScore = 0.0;
        $bestAnchor = null;

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }

            $words = preg_split('/\s+/', mb_strtolower($sentence, 'UTF-8')) ?: [];
            $cleanWords = [];
            $surfaceWords = [];
            foreach ($words as $word) {
                $surface = trim($word, '.,!?;:"\'-()[]{}');
                if ($surface === '') {
                    continue;
                }
                $surfaceWords[] = $surface;
                $cleanWords[] = PorterStemmer::stem($surface);
            }

            $sentenceScore = 0.0;
            /** @var array<string, float> $anchorCandidates surface form => score */
            $anchorCandidates = [];

            // Check unigram matches
            foreach ($cleanWords as $i => $stemmed) {
                if (isset($targetKeywords[$stemmed])) {
                    $idfValue = $idf[$stemmed] ?? 1.0;
                    $sentenceScore += $idfValue;
                    $anchorCandidates[$surfaceWords[$i]] = $idfValue;
                }
            }

            // Check bigram matches
            for ($i = 0, $len = count($cleanWords) - 1; $i < $len; $i++) {
                $bigram = $cleanWords[$i] . ' ' . $cleanWords[$i + 1];
                if (isset($targetKeywords[$bigram])) {
                    $idfValue = ($idf[$cleanWords[$i]] ?? 1.0) + ($idf[$cleanWords[$i + 1]] ?? 1.0);
                    $bigramScore = $idfValue * 1.5;
                    $sentenceScore += $bigramScore;
                    $surfaceBigram = $surfaceWords[$i] . ' ' . $surfaceWords[$i + 1];
                    $anchorCandidates[$surfaceBigram] = $bigramScore;
                }
            }

            if ($sentenceScore > $bestSentenceScore && $anchorCandidates !== []) {
                $bestSentenceScore = $sentenceScore;
                $bestSentence = $sentence;
                // Pick highest-scoring anchor candidate
                arsort($anchorCandidates);
                $bestAnchor = array_key_first($anchorCandidates);
            }
        }

        if ($bestSentence === null || $bestAnchor === null) {
            return null;
        }

        return ['sentence' => $bestSentence, 'anchor' => $bestAnchor];
    }

    /**
     * Find the best phrase in the source entry's CKEditor content that matches the target entry's keywords.
     *
     * @param array<string, float> $idf term => IDF value
     * @return array{phrase: string, fieldHandle: string}|null
     */
    public function findBestPhrase(int $sourceId, int $targetId, int $siteId, array $idf = []): ?array
    {
        $links = Plugin::getInstance()?->links;
        if ($links === null) {
            return null;
        }

        // Get target keywords from corpus (stemmed keyword => weight)
        $corpus = $links->index->loadCorpus($siteId);
        $targetKeywords = $corpus[$targetId] ?? [];
        if ($targetKeywords === []) {
            return null;
        }

        // Build stemmed target keywords map
        $stemmedTargetKeywords = [];
        foreach ($targetKeywords as $keyword => $weight) {
            $stemmed = PorterStemmer::stem(mb_strtolower((string) $keyword, 'UTF-8'));
            $stemmedTargetKeywords[$stemmed] = $weight;
        }

        // Get source entry
        /** @var Entry|null $sourceEntry */
        $sourceEntry = Entry::find()->id($sourceId)->siteId($siteId)->status(null)->one();
        if ($sourceEntry === null) {
            return null;
        }

        // Get CKEditor fields from the source entry
        $layout = $sourceEntry->getFieldLayout();
        if ($layout === null) {
            return null;
        }

        /** @var list<string> $allSentences */
        $allSentences = [];
        /** @var array<int, string> $sentenceFieldMap sentence index => field handle */
        $sentenceFieldMap = [];

        foreach ($layout->getCustomFields() as $field) {
            $class = get_class($field);
            $classLower = mb_strtolower($class, 'UTF-8');
            if (!str_contains($classLower, 'ckeditor')) {
                continue;
            }
            $handle = $field->handle;
            try {
                $value = $sourceEntry->getFieldValue($handle);
            } catch (InvalidFieldException $e) {
                Craft::warning("Beacon: Invalid field handle '{$handle}' while finding phrase for entry {$sourceId}: {$e->getMessage()}", 'beacon');
                continue;
            }
            $html = is_object($value) && method_exists($value, '__toString') ? (string) $value : (is_string($value) ? $value : '');
            if ($html === '') {
                continue;
            }
            $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            $sentences = SentenceSplitter::split($text);
            foreach ($sentences as $sentence) {
                $sentence = trim($sentence);
                if ($sentence === '') {
                    continue;
                }
                $idx = count($allSentences);
                $allSentences[] = $sentence;
                $sentenceFieldMap[$idx] = $handle;
            }
        }

        if ($allSentences === []) {
            return null;
        }

        $result = $this->findBestAnchor($allSentences, $stemmedTargetKeywords, $idf);
        if ($result === null) {
            return null;
        }

        // Find the field handle for the winning sentence
        $sentenceIdx = array_search($result['sentence'], $allSentences, true);
        $fieldHandle = $sentenceFieldMap[$sentenceIdx] ?? null;
        if ($fieldHandle === null) {
            return null;
        }

        return ['phrase' => $result['anchor'], 'fieldHandle' => $fieldHandle];
    }

    /**
     * Delete all suggestion rows where the given element is the source or target, across ALL sites.
     * Called from the Entry::EVENT_AFTER_DELETE handler when an element is fully removed,
     * so cross-site deletion is intentional.
     *
     * @param int $elementId The element ID whose rows should be removed.
     */
    public function deleteByElementId(int $elementId): void
    {
        LinkSuggestionRecord::deleteAll(['sourceElementId' => $elementId]);
        LinkSuggestionRecord::deleteAll(['targetElementId' => $elementId]);
    }

    public function clearAll(): void
    {
        LinkSuggestionRecord::deleteAll();
    }
}
