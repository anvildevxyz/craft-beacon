<?php

namespace anvildev\beacon\helpers\links;

class KeywordExtractor
{
    /** @var array<string, true> */
    private array $stopWords;

    public function __construct(
        private int $maxKeywords = 50,
        private int $minLength = 3,
        string $language = 'en',
    ) {
        $this->stopWords = array_fill_keys(self::getStopWords($language), true);
    }

    /**
     * Extract keywords with weights from text.
     *
     * @return array<string, float> keyword => weight map, sorted by weight descending
     */
    public function extract(string $text, float $positionMultiplier = 1.0): array
    {
        $text = $this->normalizeText($text);
        if (trim($text) === '') {
            return [];
        }

        $words = $this->tokenize($text);
        $words = $this->removeStopWords($words);
        if ($words === []) {
            return [];
        }

        // Stem each word and track surface forms
        /** @var array<string, array<string, int>> $stemGroups stem => [surfaceForm => count] */
        $stemGroups = [];
        $stemmedWords = [];

        foreach ($words as $word) {
            $stem = PorterStemmer::stem($word);
            $stemGroups[$stem][$word] = ($stemGroups[$stem][$word] ?? 0) + 1;
            $stemmedWords[] = $stem;
        }

        // Build unigram weights grouped by stem
        /** @var array<string, float> $unigramWeights stem => weight */
        $unigramWeights = [];
        foreach ($stemGroups as $stem => $forms) {
            $totalCount = array_sum($forms);
            $unigramWeights[$stem] = (float) $totalCount * $positionMultiplier;
        }

        // Build bigram weights grouped by stemmed bigram
        // Skip bigrams where both words share the same stem (redundant)
        /** @var array<string, array<string, int>> $bigramStemGroups stemBigram => [surfaceBigram => count] */
        $bigramStemGroups = [];
        for ($i = 0, $count = count($words) - 1; $i < $count; $i++) {
            if ($stemmedWords[$i] === $stemmedWords[$i + 1]) {
                continue;
            }
            $stemBigram = $stemmedWords[$i] . ' ' . $stemmedWords[$i + 1];
            $surfaceBigram = $words[$i] . ' ' . $words[$i + 1];
            $bigramStemGroups[$stemBigram][$surfaceBigram] = ($bigramStemGroups[$stemBigram][$surfaceBigram] ?? 0) + 1;
        }

        // Only keep bigrams that appear more than once
        $bigramStemGroups = array_filter($bigramStemGroups, function(array $forms) {
            return array_sum($forms) > 1;
        });

        // Build scored results: surface form => weight
        $scored = [];

        // Add unigrams (using most frequent surface form per stem)
        foreach ($stemGroups as $stem => $forms) {
            $surfaceForm = $this->mostFrequentForm($forms);
            $scored[$surfaceForm] = $unigramWeights[$stem];
        }

        // Add bigrams with 2x boost (using most frequent surface bigram)
        foreach ($bigramStemGroups as $stemBigram => $forms) {
            $surfaceBigram = $this->mostFrequentForm($forms);
            $totalCount = array_sum($forms);
            $scored[$surfaceBigram] = (float) $totalCount * 2.0 * $positionMultiplier;
        }

        arsort($scored);

        return array_slice($scored, 0, $this->maxKeywords, true);
    }

    /**
     * Extract keywords from structured content with position-based weighting.
     *
     * @param array{title: string, headings: string[], body: string} $content
     * @return array<string, float> keyword => weight map
     */
    public function extractStructured(array $content): array
    {
        $titleKeywords = $this->extract($content['title'], 3.0);
        $bodyKeywords = $this->extract($content['body'], 1.0);

        $headingText = implode(' ', $content['headings']);
        $headingKeywords = $this->extract($headingText, 2.0);

        // Merge weights: for matching stems, sum the weights
        // We need to merge by stem to combine across sections
        $merged = $this->mergeWeightsByStem($titleKeywords, $headingKeywords, $bodyKeywords);

        arsort($merged);

        return array_slice($merged, 0, $this->maxKeywords, true);
    }

    /**
     * Hash for weighted keyword arrays.
     *
     * @param array<string, float> $keywords
     */
    public static function hashWeighted(array $keywords): string
    {
        $keys = array_keys($keywords);
        sort($keys);
        $parts = [];
        foreach ($keys as $key) {
            $parts[] = $key . ':' . $keywords[$key];
        }

        return hash('sha256', implode('|', $parts));
    }

    /**
     * Merge multiple weighted keyword arrays by stem, summing weights.
     * Uses the surface form from whichever section has the highest weight for that stem.
     *
     * @param array<string, float> ...$keywordSets
     * @return array<string, float>
     */
    private function mergeWeightsByStem(array ...$keywordSets): array
    {
        /** @var array<string, array{surface: string, weight: float, maxSectionWeight: float}> $stemMap */
        $stemMap = [];

        foreach ($keywordSets as $keywords) {
            foreach ($keywords as $surface => $weight) {
                // For bigrams, stem both words
                $words = explode(' ', $surface);
                $stemKey = implode(' ', array_map([PorterStemmer::class, 'stem'], $words));

                if (isset($stemMap[$stemKey])) {
                    $stemMap[$stemKey]['weight'] += $weight;
                    // Keep surface form from highest-weighted section
                    if ($weight > $stemMap[$stemKey]['maxSectionWeight']) {
                        $stemMap[$stemKey]['surface'] = $surface;
                        $stemMap[$stemKey]['maxSectionWeight'] = $weight;
                    }
                } else {
                    $stemMap[$stemKey] = [
                        'surface' => $surface,
                        'weight' => $weight,
                        'maxSectionWeight' => $weight,
                    ];
                }
            }
        }

        $result = [];
        foreach ($stemMap as $data) {
            $result[$data['surface']] = $data['weight'];
        }

        return $result;
    }

    /**
     * Get the most frequent form from a frequency map.
     *
     * @param array<string, int> $forms
     */
    private function mostFrequentForm(array $forms): string
    {
        arsort($forms);

        return (string) array_key_first($forms);
    }

    private function normalizeText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /** @return string[] */
    private function tokenize(string $text): array
    {
        $words = explode(' ', $text);

        return array_values(array_filter($words, fn(string $word) => mb_strlen($word) >= $this->minLength));
    }

    /**
     * @param string[] $words
     * @return string[]
     */
    private function removeStopWords(array $words): array
    {
        return array_values(array_filter($words, fn(string $word) => !isset($this->stopWords[$word])));
    }

    /** @return string[] */
    private static function getStopWords(string $language): array
    {
        if (!preg_match('/^[a-z]{2}$/', $language)) {
            $language = 'en'; // fallback to prevent path traversal
        }
        $file = __DIR__ . '/stopwords/' . $language . '.php';
        if (file_exists($file)) {
            return require $file;
        }

        // Fall back to English stop words
        if ($language !== 'en') {
            if (class_exists(\Craft::class)) {
                \Craft::debug("Beacon: Stop words file not found for language '{$language}', falling back to English.", 'beacon');
            }
            $enFile = __DIR__ . '/stopwords/en.php';
            if (file_exists($enFile)) {
                return require $enFile;
            }
        }

        return [];
    }
}
