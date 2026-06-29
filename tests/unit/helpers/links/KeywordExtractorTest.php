<?php

namespace anvildev\beacon\tests\unit\helpers\links;

use anvildev\beacon\helpers\links\KeywordExtractor;
use PHPUnit\Framework\TestCase;

class KeywordExtractorTest extends TestCase
{
    private KeywordExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new KeywordExtractor();
    }

    public function testExtractReturnsAssociativeArray(): void
    {
        $result = $this->extractor->extract('craft plugin development');
        $this->assertIsArray($result);
        foreach ($result as $key => $value) {
            $this->assertIsString($key);
            $this->assertIsFloat($value);
        }
    }

    public function testRemovesStopWords(): void
    {
        $result = $this->extractor->extract('the quick brown fox jumps over the lazy dog');
        $this->assertArrayNotHasKey('the', $result);
        $this->assertArrayNotHasKey('over', $result);
        $keys = array_keys($result);
        $keyString = implode(' ', $keys);
        $this->assertStringContainsString('quick', $keyString);
        $this->assertStringContainsString('brown', $keyString);
    }

    public function testRespectsMinKeywordLength(): void
    {
        $extractor = new KeywordExtractor(minLength: 4);
        $result = $extractor->extract('the big cat sat on a mat');
        $this->assertSame([], $result);

        $result2 = $extractor->extract('the excellent framework provides stability');
        $this->assertNotEmpty($result2);
        foreach (array_keys($result2) as $keyword) {
            $words = explode(' ', $keyword);
            foreach ($words as $word) {
                $this->assertGreaterThanOrEqual(4, mb_strlen($word));
            }
        }
    }

    public function testBigramsHaveHigherWeightThanSingleOccurrences(): void
    {
        $result = $this->extractor->extract(
            'craft plugin development is fun. craft plugin architecture is important.'
        );
        $this->assertArrayHasKey('craft plugin', $result);
        $singleWords = array_filter($result, fn($weight, $key) => !str_contains($key, ' '), ARRAY_FILTER_USE_BOTH);
        if (count($singleWords) > 0) {
            $minSingleWeight = min($singleWords);
            $this->assertGreaterThan($minSingleWeight, $result['craft plugin']);
        }
    }

    public function testLimitsKeywordCount(): void
    {
        $extractor = new KeywordExtractor(maxKeywords: 5);
        $text = 'alpha bravo charlie delta echo foxtrot golf hotel india juliet kilo lima';
        $result = $extractor->extract($text);
        $this->assertLessThanOrEqual(5, count($result));
    }

    public function testNormalizesToLowercase(): void
    {
        $result = $this->extractor->extract('Craft CMS Plugin Development');
        foreach (array_keys($result) as $keyword) {
            $this->assertSame(strtolower($keyword), $keyword);
        }
    }

    public function testHandlesEmptyInput(): void
    {
        $result = $this->extractor->extract('');
        $this->assertSame([], $result);
    }

    public function testHandlesOnlyStopWords(): void
    {
        $result = $this->extractor->extract('the and or but if');
        $this->assertSame([], $result);
    }

    public function testDecodesHtmlEntities(): void
    {
        $result = $this->extractor->extract('craft&amp;commerce development isn&#39;t easy');
        $keys = array_keys($result);
        $keyString = implode(' ', $keys);
        $this->assertTrue(
            str_contains($keyString, 'craft') || str_contains($keyString, 'commerc'),
            'Expected craft or commerce in keywords'
        );
    }

    public function testHashWeightedConsistency(): void
    {
        $result1 = $this->extractor->extract('craft plugin development');
        $result2 = $this->extractor->extract('craft plugin development');
        $this->assertSame(
            KeywordExtractor::hashWeighted($result1),
            KeywordExtractor::hashWeighted($result2)
        );
    }

    public function testHashWeightedDifferentForDifferentKeywords(): void
    {
        $result1 = $this->extractor->extract('craft plugin development');
        $result2 = $this->extractor->extract('wordpress theme customization');
        $this->assertNotSame(
            KeywordExtractor::hashWeighted($result1),
            KeywordExtractor::hashWeighted($result2)
        );
    }

    public function testStemmingCollapsesVariants(): void
    {
        $result = $this->extractor->extract(
            'optimization optimizing optimized optimize'
        );
        $keys = array_keys($result);
        $this->assertCount(1, $keys);
        $weight = reset($result);
        $this->assertEquals(4.0, $weight);
    }

    public function testStemmingUseMostFrequentSurfaceForm(): void
    {
        $result = $this->extractor->extract('running running running run');
        $keys = array_keys($result);
        $this->assertCount(1, $keys);
        $this->assertSame('running', $keys[0]);
    }

    public function testExtractStructuredTitleGetsHigherWeight(): void
    {
        $result = $this->extractor->extractStructured([
            'title' => 'optimization',
            'headings' => [],
            'body' => 'development',
        ]);
        $keys = array_keys($result);
        $keyString = implode(' ', $keys);
        $titleWeight = null;
        $bodyWeight = null;
        foreach ($result as $keyword => $weight) {
            if (str_contains('optimization', $keyword) || str_contains($keyword, 'optim')) {
                $titleWeight = $weight;
            }
            if (str_contains('development', $keyword) || str_contains($keyword, 'develop')) {
                $bodyWeight = $weight;
            }
        }
        $this->assertNotNull($titleWeight, 'Title keyword should be present');
        $this->assertNotNull($bodyWeight, 'Body keyword should be present');
        $this->assertGreaterThan($bodyWeight, $titleWeight);
    }

    public function testExtractStructuredHeadingsGetHigherWeightThanBody(): void
    {
        $result = $this->extractor->extractStructured([
            'title' => '',
            'headings' => ['architecture'],
            'body' => 'development',
        ]);
        $headingWeight = null;
        $bodyWeight = null;
        foreach ($result as $keyword => $weight) {
            if (str_contains($keyword, 'architectur') || str_contains('architecture', $keyword)) {
                $headingWeight = $weight;
            }
            if (str_contains($keyword, 'develop') || str_contains('development', $keyword)) {
                $bodyWeight = $weight;
            }
        }
        $this->assertNotNull($headingWeight, 'Heading keyword should be present');
        $this->assertNotNull($bodyWeight, 'Body keyword should be present');
        $this->assertGreaterThan($bodyWeight, $headingWeight);
    }

    public function testExtractStructuredHandlesEmptyTitle(): void
    {
        $result = $this->extractor->extractStructured([
            'title' => '',
            'headings' => [],
            'body' => 'plugin development',
        ]);
        $this->assertNotEmpty($result);
        foreach ($result as $key => $value) {
            $this->assertIsString($key);
            $this->assertIsFloat($value);
        }
    }

    public function testExtractStructuredMergesWeights(): void
    {
        $result = $this->extractor->extractStructured([
            'title' => 'optimization',
            'headings' => ['optimization'],
            'body' => 'optimization',
        ]);
        $keys = array_keys($result);
        $this->assertCount(1, $keys);
        $weight = reset($result);
        $this->assertEquals(6.0, $weight);
    }

    public function testPositionMultiplierParameter(): void
    {
        $result1 = $this->extractor->extract('optimization', 1.0);
        $result2 = $this->extractor->extract('optimization', 3.0);
        $keys1 = array_keys($result1);
        $keys2 = array_keys($result2);
        $this->assertSame($keys1, $keys2);
        $weight1 = reset($result1);
        $weight2 = reset($result2);
        $this->assertEquals($weight1 * 3, $weight2);
    }
}
