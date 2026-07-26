<?php

namespace anvildev\beacon\tests\bench;

use anvildev\beacon\helpers\links\TfIdfScorer;

/**
 * Pure-PHP benchmark for the Links suggestion-scoring hot path (no Craft
 * bootstrap required). Guards the per-save cost of ranking every candidate
 * entry against a source document's keyword profile.
 *
 * @BeforeMethods({"setUp"})
 * @Iterations(10)
 * @Revs(20)
 */
class LinkScorerBench
{
    private const CORPUS_SIZE = 500;
    private const KEYWORDS_PER_DOC = 20;
    private const VOCABULARY_SIZE = 800;

    /** @var array<int, array<string, float>> */
    private array $corpus;

    /** @var array<string, float> */
    private array $sourceKeywords;

    private TfIdfScorer $scorer;

    public function setUp(): void
    {
        // Deterministic synthetic corpus so iterations are comparable run to run.
        mt_srand(1337);

        $this->corpus = [];
        for ($id = 1; $id <= self::CORPUS_SIZE; $id++) {
            $keywords = [];
            for ($k = 0; $k < self::KEYWORDS_PER_DOC; $k++) {
                $term = 'term' . mt_rand(0, self::VOCABULARY_SIZE - 1);
                $keywords[$term] = (float) mt_rand(1, 5);
            }
            $this->corpus[$id] = $keywords;
        }

        // Source profile overlaps the vocabulary so scoring does real work.
        $this->sourceKeywords = [];
        for ($k = 0; $k < self::KEYWORDS_PER_DOC; $k++) {
            $term = 'term' . mt_rand(0, self::VOCABULARY_SIZE - 1);
            $this->sourceKeywords[$term] = (float) mt_rand(1, 5);
        }

        $this->scorer = new TfIdfScorer($this->corpus);
    }

    public function benchScoreAll(): void
    {
        $this->scorer->scoreAll($this->sourceKeywords, $this->corpus, [1]);
    }
}
