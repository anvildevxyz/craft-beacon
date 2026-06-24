<?php

namespace anvildev\beacon\tests\unit\helpers\links;

use anvildev\beacon\helpers\links\PorterStemmer;
use PHPUnit\Framework\TestCase;

class PorterStemmerTest extends TestCase
{
    public function testEmptyString(): void
    {
        $this->assertSame('', PorterStemmer::stem(''));
    }

    public function testShortWordsPreserved(): void
    {
        // Two-letter words are below the mb_strlen < 3 threshold
        $this->assertSame('an', PorterStemmer::stem('an'));
        $this->assertSame('go', PorterStemmer::stem('go'));

        // Three-letter words enter the algorithm but may be unchanged
        $this->assertSame('seo', PorterStemmer::stem('seo'));
        $this->assertSame('api', PorterStemmer::stem('api'));

        // 'cms' loses its trailing s per Porter step 1a — this is expected
        $this->assertSame('cm', PorterStemmer::stem('cms'));
    }

    public function testPluralStemming(): void
    {
        $this->assertSame(PorterStemmer::stem('car'), PorterStemmer::stem('cars'));
        $this->assertSame(PorterStemmer::stem('kiss'), PorterStemmer::stem('kisses'));
        $this->assertSame(PorterStemmer::stem('pony'), PorterStemmer::stem('ponies'));
    }

    public function testIngSuffix(): void
    {
        $searchStem = PorterStemmer::stem('search');
        $this->assertSame($searchStem, PorterStemmer::stem('searching'));
    }

    public function testEdSuffix(): void
    {
        $searchStem = PorterStemmer::stem('search');
        $this->assertSame($searchStem, PorterStemmer::stem('searched'));
    }

    public function testTionSuffix(): void
    {
        $stem = PorterStemmer::stem('optimization');
        $this->assertNotEmpty($stem);
        $this->assertNotSame('optimization', $stem);
    }

    public function testNessSuffix(): void
    {
        $stem = PorterStemmer::stem('happiness');
        $this->assertNotEmpty($stem);
        $this->assertNotSame('happiness', $stem);
    }

    public function testMentSuffix(): void
    {
        $stem = PorterStemmer::stem('replacement');
        $this->assertNotEmpty($stem);
        $this->assertNotSame('replacement', $stem);
    }

    public function testAbleSuffix(): void
    {
        $stem = PorterStemmer::stem('adjustable');
        $this->assertNotEmpty($stem);
        $this->assertNotSame('adjustable', $stem);
    }

    public function testLySuffix(): void
    {
        $stem = PorterStemmer::stem('happily');
        $this->assertNotEmpty($stem);
        $this->assertNotSame('happily', $stem);
    }

    public function testIzeSuffix(): void
    {
        $stem = PorterStemmer::stem('optimize');
        $this->assertNotEmpty($stem);
        $this->assertNotSame('optimize', $stem);
    }

    /**
     * Critical invariant: word variants must produce the same stem.
     */
    public function testOptimizationVariantsConverge(): void
    {
        $stems = [
            PorterStemmer::stem('optimization'),
            PorterStemmer::stem('optimizing'),
            PorterStemmer::stem('optimized'),
        ];

        $this->assertSame($stems[0], $stems[1], 'optimization and optimizing should have the same stem');
        $this->assertSame($stems[0], $stems[2], 'optimization and optimized should have the same stem');
    }

    /**
     * Critical invariant: search variants must produce the same stem.
     */
    public function testSearchVariantsConverge(): void
    {
        $stems = [
            PorterStemmer::stem('search'),
            PorterStemmer::stem('searching'),
            PorterStemmer::stem('searched'),
        ];

        $this->assertSame($stems[0], $stems[1], 'search and searching should have the same stem');
        $this->assertSame($stems[0], $stems[2], 'search and searched should have the same stem');
    }

    public function testConnectVariantsConverge(): void
    {
        $stems = [
            PorterStemmer::stem('connect'),
            PorterStemmer::stem('connecting'),
            PorterStemmer::stem('connected'),
            PorterStemmer::stem('connection'),
        ];

        $this->assertSame($stems[0], $stems[1], 'connect and connecting should have the same stem');
        $this->assertSame($stems[0], $stems[2], 'connect and connected should have the same stem');
        $this->assertSame($stems[0], $stems[3], 'connect and connection should have the same stem');
    }

    public function testGeneralizeVariantsConverge(): void
    {
        $stems = [
            PorterStemmer::stem('generalize'),
            PorterStemmer::stem('generalized'),
            PorterStemmer::stem('generalizing'),
            PorterStemmer::stem('generalization'),
        ];

        $this->assertSame($stems[0], $stems[1]);
        $this->assertSame($stems[0], $stems[2]);
        $this->assertSame($stems[0], $stems[3]);
    }

    public function testSingleCharacterWord(): void
    {
        $this->assertSame('a', PorterStemmer::stem('a'));
    }
}
