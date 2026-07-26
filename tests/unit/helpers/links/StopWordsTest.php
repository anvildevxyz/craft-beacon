<?php

namespace anvildev\beacon\tests\unit\helpers\links;

use anvildev\beacon\helpers\links\KeywordExtractor;
use PHPUnit\Framework\TestCase;

class StopWordsTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function languageProvider(): array
    {
        return [
            'English' => ['en'],
            'German' => ['de'],
            'Spanish' => ['es'],
            'French' => ['fr'],
            'Italian' => ['it'],
            'Dutch' => ['nl'],
            'Portuguese' => ['pt'],
        ];
    }

    /** @dataProvider languageProvider */
    public function testStopWordsFileExists(string $language): void
    {
        $file = dirname(__DIR__, 4) . '/src/helpers/links/stopwords/' . $language . '.php';
        $this->assertFileExists($file, "Stop words file for '{$language}' does not exist.");
    }

    /** @dataProvider languageProvider */
    public function testStopWordsFileReturnsNonEmptyArray(string $language): void
    {
        $file = dirname(__DIR__, 4) . '/src/helpers/links/stopwords/' . $language . '.php';
        $words = require $file;

        $this->assertIsArray($words, "Stop words for '{$language}' should be an array.");
        $this->assertNotEmpty($words, "Stop words for '{$language}' should not be empty.");
    }

    /** @dataProvider languageProvider */
    public function testStopWordsFileContainsOnlyStrings(string $language): void
    {
        $file = dirname(__DIR__, 4) . '/src/helpers/links/stopwords/' . $language . '.php';
        $words = require $file;

        foreach ($words as $word) {
            $this->assertIsString($word, "All stop words for '{$language}' must be strings.");
        }
    }

    /** @dataProvider languageProvider */
    public function testStopWordsFileContainsAtLeast100Words(string $language): void
    {
        $file = dirname(__DIR__, 4) . '/src/helpers/links/stopwords/' . $language . '.php';
        $words = require $file;

        $this->assertGreaterThanOrEqual(100, count($words), "Stop words for '{$language}' should contain at least 100 words.");
    }

    /** @dataProvider languageProvider */
    public function testKeywordExtractorWorksWithLanguage(string $language): void
    {
        $extractor = new KeywordExtractor(language: $language);
        $result = $extractor->extract('hello world testing language extraction');

        $this->assertIsArray($result);
    }

    public function testUnknownLanguageFallsBackToEnglish(): void
    {
        // Extract with an unknown language — should still filter English stop words
        $extractor = new KeywordExtractor(maxKeywords: 50, minLength: 3, language: 'xx');
        $result = $extractor->extract('the quick brown fox jumps over the lazy dog');
        // "the" and "over" are English stop words — they should be filtered via fallback
        $this->assertArrayNotHasKey('the', $result);
        $this->assertArrayHasKey('quick', $result);
        $this->assertArrayHasKey('brown', $result);
    }

    public function testEnglishStopWordsAreFiltered(): void
    {
        $extractor = new KeywordExtractor(language: 'en');
        $result = $extractor->extract('the quick brown fox jumps over the lazy dog');

        $this->assertArrayNotHasKey('the', $result);
        $this->assertArrayNotHasKey('over', $result);
        $this->assertArrayHasKey('quick', $result);
        $this->assertArrayHasKey('brown', $result);
    }

    public function testGermanStopWordsAreFiltered(): void
    {
        $extractor = new KeywordExtractor(language: 'de');
        $result = $extractor->extract('der schnelle braune fuchs springt über den faulen hund');

        $this->assertArrayNotHasKey('der', $result);
        $this->assertArrayNotHasKey('den', $result);
        // Stemmed forms: check that stemmed variants of these words are present
        $keys = array_keys($result);
        $hasSchnelle = count(array_filter($keys, fn($k) => str_starts_with($k, 'schnell'))) > 0;
        $hasBraune = count(array_filter($keys, fn($k) => str_starts_with($k, 'braun'))) > 0;
        $this->assertTrue($hasSchnelle, 'Expected a keyword starting with "schnell"');
        $this->assertTrue($hasBraune, 'Expected a keyword starting with "braun"');
    }

    public function testFrenchStopWordsAreFiltered(): void
    {
        $extractor = new KeywordExtractor(language: 'fr');
        $result = $extractor->extract('le renard brun rapide saute par dessus le chien paresseux');

        $this->assertArrayNotHasKey('le', $result);
        $this->assertArrayNotHasKey('par', $result);
        // Stemmed forms: check that stemmed variants are present
        $keys = array_keys($result);
        $hasRenard = count(array_filter($keys, fn($k) => str_starts_with($k, 'renard'))) > 0;
        $hasChien = count(array_filter($keys, fn($k) => str_starts_with($k, 'chien'))) > 0;
        $this->assertTrue($hasRenard, 'Expected a keyword starting with "renard"');
        $this->assertTrue($hasChien, 'Expected a keyword starting with "chien"');
    }
}
