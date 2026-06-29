<?php

namespace anvildev\beacon\tests\unit\helpers\links;

use anvildev\beacon\helpers\links\TfIdfScorer;
use PHPUnit\Framework\TestCase;

class TfIdfScorerTest extends TestCase
{
    public function testScoreWithIdenticalKeywords(): void
    {
        $corpus = [
            1 => ['craft' => 1.0, 'cms' => 1.0, 'plugin' => 1.0],
            2 => ['craft' => 1.0, 'cms' => 1.0, 'plugin' => 1.0],
            3 => ['wordpress' => 1.0, 'theme' => 1.0, 'design' => 1.0],
        ];
        $scorer = new TfIdfScorer($corpus);
        $score = $scorer->score(
            ['craft' => 1.0, 'cms' => 1.0, 'plugin' => 1.0],
            ['craft' => 1.0, 'cms' => 1.0, 'plugin' => 1.0],
        );
        $this->assertGreaterThan(0, $score);
    }

    public function testScoreWithNoOverlap(): void
    {
        $corpus = [
            1 => ['craft' => 1.0, 'cms' => 1.0, 'plugin' => 1.0],
            2 => ['wordpress' => 1.0, 'theme' => 1.0, 'design' => 1.0],
        ];
        $scorer = new TfIdfScorer($corpus);
        $score = $scorer->score(
            ['craft' => 1.0, 'cms' => 1.0, 'plugin' => 1.0],
            ['wordpress' => 1.0, 'theme' => 1.0, 'design' => 1.0],
        );
        $this->assertSame(0.0, $score);
    }

    public function testRareWordsScoreHigher(): void
    {
        $corpus = [
            1 => ['craft' => 1.0, 'availability' => 1.0, 'booking' => 1.0],
            2 => ['craft' => 1.0, 'commerce' => 1.0, 'order' => 1.0],
            3 => ['craft' => 1.0, 'seo' => 1.0, 'content' => 1.0],
        ];
        $scorer = new TfIdfScorer($corpus);
        $scoreRare = $scorer->score(
            ['craft' => 1.0, 'availability' => 1.0, 'booking' => 1.0],
            ['availability' => 1.0, 'scheduling' => 1.0, 'calendar' => 1.0],
        );
        $scoreCommon = $scorer->score(
            ['craft' => 1.0, 'availability' => 1.0, 'booking' => 1.0],
            ['craft' => 1.0, 'design' => 1.0, 'layout' => 1.0],
        );
        $this->assertGreaterThan($scoreCommon, $scoreRare);
    }

    public function testEmptyCorpus(): void
    {
        $scorer = new TfIdfScorer([]);
        $this->assertSame(0.0, $scorer->score(['craft' => 1.0], ['craft' => 1.0]));
    }

    public function testEmptyKeywords(): void
    {
        $corpus = [1 => ['craft' => 1.0]];
        $scorer = new TfIdfScorer($corpus);
        $this->assertSame(0.0, $scorer->score([], ['craft' => 1.0]));
        $this->assertSame(0.0, $scorer->score(['craft' => 1.0], []));
    }

    public function testScoreAllReturnsRankedResults(): void
    {
        $corpus = [
            1 => ['craft' => 1.0, 'cms' => 1.0, 'plugin' => 1.0, 'seo' => 1.0],
            2 => ['craft' => 1.0, 'seo' => 1.0, 'links' => 1.0],
            3 => ['wordpress' => 1.0, 'theme' => 1.0],
        ];
        $scorer = new TfIdfScorer($corpus);
        $results = $scorer->scoreAll(
            ['craft' => 1.0, 'cms' => 1.0, 'plugin' => 1.0, 'seo' => 1.0],
            $corpus,
            excludeIds: [1],
        );
        $this->assertNotEmpty($results);
        $this->assertSame(2, $results[0]['elementId']);
        $this->assertGreaterThan(0, $results[0]['score']);
    }

    public function testScoreAllExcludesSpecifiedIds(): void
    {
        $corpus = [
            1 => ['craft' => 1.0, 'cms' => 1.0],
            2 => ['craft' => 1.0, 'seo' => 1.0],
        ];
        $scorer = new TfIdfScorer($corpus);
        $results = $scorer->scoreAll(
            ['craft' => 1.0, 'cms' => 1.0],
            $corpus,
            excludeIds: [1, 2],
        );
        $this->assertCount(0, $results);
    }

    public function testScoreAllExcludesZeroScores(): void
    {
        $corpus = [
            1 => ['craft' => 1.0, 'cms' => 1.0],
            2 => ['wordpress' => 1.0, 'theme' => 1.0],
        ];
        $scorer = new TfIdfScorer($corpus);
        $results = $scorer->scoreAll(['craft' => 1.0, 'cms' => 1.0], $corpus);
        foreach ($results as $result) {
            $this->assertGreaterThan(0.0, $result['score']);
        }
    }

    public function testHigherWeightKeywordsContributeMore(): void
    {
        $corpus = [
            1 => ['alpha' => 1.0, 'beta' => 1.0],
            2 => ['gamma' => 1.0, 'delta' => 1.0],
        ];
        $scorer = new TfIdfScorer($corpus);

        $scoreHighAlpha = $scorer->score(
            ['alpha' => 5.0, 'gamma' => 0.1],
            ['alpha' => 1.0],
        );
        $scoreHighGamma = $scorer->score(
            ['alpha' => 0.1, 'gamma' => 5.0],
            ['alpha' => 1.0],
        );
        $this->assertGreaterThan($scoreHighGamma, $scoreHighAlpha);
    }

    public function testTermsAboveMaxDfRatioAreSuppressed(): void
    {
        $corpus = [
            1 => ['craft' => 1.0, 'booking' => 1.0],
            2 => ['craft' => 1.0, 'commerce' => 1.0],
            3 => ['craft' => 1.0, 'seo' => 1.0],
        ];
        $scorer = new TfIdfScorer($corpus, maxDocumentFrequencyRatio: 0.5);

        $idf = $scorer->getIdf();
        $this->assertSame(0.0, $idf['craft']);
        $this->assertGreaterThan(0.0, $idf['booking']);
    }

    public function testTermsBelowMaxDfRatioAreNotSuppressed(): void
    {
        $corpus = [
            1 => ['craft' => 1.0, 'booking' => 1.0],
            2 => ['craft' => 1.0, 'commerce' => 1.0],
            3 => ['craft' => 1.0, 'seo' => 1.0],
        ];
        $scorer = new TfIdfScorer($corpus, maxDocumentFrequencyRatio: 0.5);

        $idf = $scorer->getIdf();
        $this->assertGreaterThan(0.0, $idf['booking']);
        $this->assertGreaterThan(0.0, $idf['commerce']);
        $this->assertGreaterThan(0.0, $idf['seo']);
    }

    public function testDefaultMaxDfRatioSuppressesNothing(): void
    {
        $corpus = [
            1 => ['craft' => 1.0, 'booking' => 1.0],
            2 => ['craft' => 1.0, 'commerce' => 1.0],
            3 => ['craft' => 1.0, 'seo' => 1.0],
        ];
        $scorer = new TfIdfScorer($corpus);

        $idf = $scorer->getIdf();
        foreach ($idf as $term => $value) {
            $this->assertGreaterThan(0.0, $value, "Term '{$term}' should not be suppressed with default maxDfRatio");
        }
    }

    public function testGetIdfReturnsAllTerms(): void
    {
        $corpus = [
            1 => ['alpha' => 1.0, 'beta' => 1.0],
            2 => ['gamma' => 1.0, 'delta' => 1.0],
        ];
        $scorer = new TfIdfScorer($corpus);

        $idf = $scorer->getIdf();
        $this->assertArrayHasKey('alpha', $idf);
        $this->assertArrayHasKey('beta', $idf);
        $this->assertArrayHasKey('gamma', $idf);
        $this->assertArrayHasKey('delta', $idf);
    }
}
