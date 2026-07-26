<?php

namespace anvildev\beacon\helpers\links;

use anvildev\beacon\types\ScoreTypes;

/** @phpstan-import-type ScoreRows from ScoreTypes */
class TfIdfScorer
{
    /** @var array<string, float> */
    private array $idf = [];

    private int $totalDocs;

    /**
     * @param array<int, array<string, float>> $corpus keyword => weight maps per document
     * @param float $maxDocumentFrequencyRatio terms appearing in more than this fraction of docs get IDF = 0
     */
    public function __construct(array $corpus, private float $maxDocumentFrequencyRatio = 1.0)
    {
        $this->totalDocs = count($corpus);
        $this->computeIdf($corpus);
    }

    /**
     * @param array<string, float> $sourceKeywords
     * @param array<string, float> $targetKeywords
     */
    public function score(array $sourceKeywords, array $targetKeywords): float
    {
        if ($sourceKeywords === [] || $targetKeywords === [] || $this->totalDocs === 0) {
            return 0.0;
        }

        $sourceVector = $this->toTfIdfVector($sourceKeywords);
        $targetVector = $this->toTfIdfVector($targetKeywords);

        return CosineSimilarity::calculate($sourceVector, $targetVector);
    }

    /**
     * @param array<string, float> $sourceKeywords
     * @param array<int, array<string, float>> $corpus
     * @param int[] $excludeIds
     * @return ScoreRows
     */
    public function scoreAll(array $sourceKeywords, array $corpus, array $excludeIds = []): array
    {
        $excludeMap = array_fill_keys($excludeIds, true);
        $results = [];

        foreach ($corpus as $elementId => $keywords) {
            if (isset($excludeMap[$elementId])) {
                continue;
            }

            $score = $this->score($sourceKeywords, $keywords);

            if ($score === 0.0) {
                continue;
            }

            $results[] = ['elementId' => $elementId, 'score' => $score];
        }

        usort($results, fn(array $a, array $b) => $b['score'] <=> $a['score']);

        return $results;
    }

    /**
     * @return array<string, float>
     */
    public function getIdf(): array
    {
        return $this->idf;
    }

    /**
     * @param array<string, float> $keywords keyword => weight map
     * @return array<string, float>
     */
    private function toTfIdfVector(array $keywords): array
    {
        $totalWeight = array_sum($keywords);

        if ($totalWeight <= 0.0) {
            return [];
        }

        $vector = [];

        foreach ($keywords as $term => $weight) {
            $idf = $this->idf[$term] ?? log($this->totalDocs + 1);

            if ($idf <= 0.0) {
                continue;
            }

            $vector[$term] = ($weight / $totalWeight) * $idf;
        }

        return $vector;
    }

    /**
     * @param array<int, array<string, float>> $corpus
     */
    private function computeIdf(array $corpus): void
    {
        $docFrequency = [];

        foreach ($corpus as $keywords) {
            foreach (array_keys($keywords) as $term) {
                $docFrequency[$term] = ($docFrequency[$term] ?? 0) + 1;
            }
        }

        foreach ($docFrequency as $term => $df) {
            if ($this->totalDocs > 0 && ($df / $this->totalDocs) > $this->maxDocumentFrequencyRatio) {
                $this->idf[$term] = 0.0;
            } else {
                $this->idf[$term] = log(($this->totalDocs + 1) / ($df + 1)) + 1;
            }
        }
    }
}
