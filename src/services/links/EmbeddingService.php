<?php

namespace anvildev\beacon\services\links;

use anvildev\beacon\helpers\links\CosineSimilarity;
use anvildev\beacon\Plugin;
use anvildev\beacon\records\LinkEmbeddingRecord;
use anvildev\beacon\types\ScoreTypes;
use Craft;
use craft\base\Component;

/** @phpstan-import-type ScoreRows from ScoreTypes */
class EmbeddingService extends Component
{
    /**
     * Throttle API requests using the Craft cache so the minimum interval is
     * enforced across all processes (web workers, queue workers, etc.).
     *
     * NOTE: Craft's default file-based cache is not atomic, so two processes
     * can read the same $last value simultaneously and both proceed without
     * sleeping. For strict single-flight coordination a Redis-backed cache
     * (or a proper mutex) would be required. The file cache is sufficient to
     * smooth burst traffic in practice.
     */
    private function throttle(): void
    {
        $cache = Craft::$app->getCache();
        $key = 'beacon:embedding:lastRequestAt';
        $minIntervalSeconds = 0.2; // 200 ms — matches the previous static-property limiter

        $last = (float) ($cache->get($key) ?: 0);
        $now = microtime(true);
        $elapsed = $now - $last;
        if ($elapsed < $minIntervalSeconds) {
            // Clamp to [0, minInterval] µs so a poisoned future timestamp in
            // the cache cannot cause an arbitrarily long (or negative) sleep.
            $sleepMicroseconds = max(0, (int) (($minIntervalSeconds - $elapsed) * 1_000_000));
            $sleepMicroseconds = min($sleepMicroseconds, (int) ($minIntervalSeconds * 1_000_000));
            usleep($sleepMicroseconds);
            $now = microtime(true);
        }
        $cache->set($key, $now, 60); // TTL prevents stale entries after long idle periods
    }

    public function truncateForEmbedding(string $text, int $maxChars = 8000): string
    {
        if (mb_strlen($text, 'UTF-8') <= $maxChars) {
            return $text;
        }
        return mb_substr($text, 0, $maxChars, 'UTF-8');
    }

    /** @return float[]|null */
    public function fetchEmbedding(string $text, string $model, ?string $apiKey = null, ?string $baseUrl = null): ?array
    {
        // Nothing to embed — skip the request (an empty input is a 400 from
        // OpenAI-compatible endpoints, and a wasted call/cost otherwise).
        if (trim($text) === '') {
            return null;
        }
        $this->throttle();
        $aiClient = Plugin::getInstance()?->aiClient;
        if ($aiClient === null) {
            return null;
        }
        try {
            $vector = $aiClient->embed($this->truncateForEmbedding($text), $model, $apiKey, $baseUrl);
            return $vector === [] ? null : $vector;
        } catch (\anvildev\beacon\services\ai\AiException $e) {
            Craft::error('Beacon links embedding request failed: ' . $e->getMessage(), 'beacon');
            return null;
        }
    }

    /** @param float[] $embedding */
    public function saveEmbedding(int $elementId, int $siteId, array $embedding, string $model): void
    {
        /** @var LinkEmbeddingRecord|null $record */
        $record = LinkEmbeddingRecord::find()->where(['elementId' => $elementId, 'siteId' => $siteId])->one();
        if ($record === null) {
            $record = new LinkEmbeddingRecord();
            $record->elementId = $elementId;
            $record->siteId = $siteId;
        }
        $record->embedding = self::serialize($embedding);
        $record->model = $model;
        $record->dateIndexed = (new \DateTime())->format('Y-m-d H:i:s');
        $record->save();
    }

    /** @return float[]|null */
    public function getEmbedding(int $elementId, int $siteId): ?array
    {
        /** @var LinkEmbeddingRecord|null $record */
        $record = LinkEmbeddingRecord::find()->where(['elementId' => $elementId, 'siteId' => $siteId])->one();
        if ($record === null) {
            return null;
        }
        return self::deserialize($record->embedding);
    }

    /**
     * @param float[] $sourceEmbedding
     * @param int[] $excludeIds
     * @return ScoreRows
     *
     * Streams embedding rows in batches of 100 so that raw binary blobs for
     * all rows are never resident in memory simultaneously. Each blob is
     * deserialized, scored, and discarded before the next record is processed.
     */
    public function scoreAll(array $sourceEmbedding, int $siteId, array $excludeIds = []): array
    {
        $excludeMap = array_fill_keys($excludeIds, true);
        $results = [];

        $baseQuery = LinkEmbeddingRecord::find()->where(['siteId' => $siteId]);

        foreach ($baseQuery->batch(100) as $batch) {
            foreach ($batch as $record) {
                /** @var LinkEmbeddingRecord $record */
                if (isset($excludeMap[$record->elementId])) {
                    continue;
                }
                // Deserialize and immediately score; the $targetEmbedding array
                // goes out of scope at the end of the iteration, allowing GC to
                // reclaim the memory before the next row is processed.
                $targetEmbedding = self::deserialize($record->embedding);
                $score = CosineSimilarity::calculateFromArrays($sourceEmbedding, $targetEmbedding);
                if ($score > 0) {
                    $results[] = ['elementId' => (int) $record->elementId, 'score' => $score];
                }
            }
        }

        usort($results, fn(array $a, array $b) => $b['score'] <=> $a['score']);
        return $results;
    }

    /** @param float[] $embedding */
    public static function serialize(array $embedding): string
    {
        return pack('f*', ...$embedding);
    }

    /** @return float[] */
    public static function deserialize(string $binary): array
    {
        $unpacked = unpack('f*', $binary);
        return $unpacked ? array_values($unpacked) : [];
    }
}
