<?php

namespace anvildev\beacon\helpers\links;

/**
 * Standard Porter stemming algorithm for English.
 *
 * Reduces inflected words to their stem so that variants like
 * "optimization", "optimizing", and "optimized" collapse to the same root.
 *
 * @see https://tartarus.org/martin/PorterStemmer/def.txt
 */
class PorterStemmer
{
    /**
     * Stem a single lowercase English word.
     */
    public static function stem(string $word): string
    {
        if (mb_strlen($word) < 3) {
            return $word;
        }

        $word = self::step1a($word);
        $word = self::step1b($word);
        $word = self::step1c($word);
        $word = self::step2($word);
        $word = self::step3($word);
        $word = self::step4($word);

        return self::step5($word);
    }

    // ──────────────────────────────────────────────
    // Step 1a — plurals
    // ──────────────────────────────────────────────

    private static function step1a(string $word): string
    {
        if (str_ends_with($word, 'sses')) {
            return substr($word, 0, -2);
        }

        if (str_ends_with($word, 'ies')) {
            return substr($word, 0, -2);
        }

        if (str_ends_with($word, 'ss')) {
            return $word;
        }

        if (str_ends_with($word, 's')) {
            return substr($word, 0, -1);
        }

        return $word;
    }

    // ──────────────────────────────────────────────
    // Step 1b — -ed / -ing
    // ──────────────────────────────────────────────

    private static function step1b(string $word): string
    {
        if (str_ends_with($word, 'eed')) {
            $stem = substr($word, 0, -3);

            return self::measure($stem) > 0 ? $stem . 'ee' : $word;
        }

        $modified = false;

        if (str_ends_with($word, 'ed')) {
            $stem = substr($word, 0, -2);
            if (self::containsVowel($stem)) {
                $word = $stem;
                $modified = true;
            }
        } elseif (str_ends_with($word, 'ing')) {
            $stem = substr($word, 0, -3);
            if (self::containsVowel($stem)) {
                $word = $stem;
                $modified = true;
            }
        }

        if ($modified) {
            if (str_ends_with($word, 'at') || str_ends_with($word, 'bl') || str_ends_with($word, 'iz')) {
                return $word . 'e';
            }

            if (self::endsWithDoubleConsonant($word) && !self::endsWith($word, ['l', 's', 'z'])) {
                return substr($word, 0, -1);
            }

            if (self::measure($word) === 1 && self::cvc($word)) {
                return $word . 'e';
            }
        }

        return $word;
    }

    // ──────────────────────────────────────────────
    // Step 1c — terminal y → i
    // ──────────────────────────────────────────────

    private static function step1c(string $word): string
    {
        if (str_ends_with($word, 'y')) {
            $stem = substr($word, 0, -1);
            if (self::containsVowel($stem)) {
                return $stem . 'i';
            }
        }

        return $word;
    }

    // ──────────────────────────────────────────────
    // Step 2 — double suffix → single
    // ──────────────────────────────────────────────

    private static function step2(string $word): string
    {
        $mappings = [
            'ational' => 'ate',
            'tional' => 'tion',
            'enci' => 'ence',
            'anci' => 'ance',
            'izer' => 'ize',
            'abli' => 'able',
            'alli' => 'al',
            'entli' => 'ent',
            'eli' => 'e',
            'ousli' => 'ous',
            'ization' => 'ize',
            'ation' => 'ate',
            'ator' => 'ate',
            'alism' => 'al',
            'iveness' => 'ive',
            'fulness' => 'ful',
            'ousness' => 'ous',
            'aliti' => 'al',
            'iviti' => 'ive',
            'biliti' => 'ble',
        ];

        foreach ($mappings as $suffix => $replacement) {
            if (str_ends_with($word, $suffix)) {
                $stem = substr($word, 0, -strlen($suffix));
                if (self::measure($stem) > 0) {
                    return $stem . $replacement;
                }

                return $word;
            }
        }

        return $word;
    }

    // ──────────────────────────────────────────────
    // Step 3 — remove -ful, -ness, -ment, etc.
    // ──────────────────────────────────────────────

    private static function step3(string $word): string
    {
        $mappings = [
            'icate' => 'ic',
            'ative' => '',
            'alize' => 'al',
            'iciti' => 'ic',
            'ical' => 'ic',
            'ful' => '',
            'ness' => '',
        ];

        foreach ($mappings as $suffix => $replacement) {
            if (str_ends_with($word, $suffix)) {
                $stem = substr($word, 0, -strlen($suffix));
                if (self::measure($stem) > 0) {
                    return $stem . $replacement;
                }

                return $word;
            }
        }

        return $word;
    }

    // ──────────────────────────────────────────────
    // Step 4 — remove suffixes (m > 1)
    // ──────────────────────────────────────────────

    private static function step4(string $word): string
    {
        $suffixes = [
            'al', 'ance', 'ence', 'er', 'ic', 'able', 'ible',
            'ant', 'ement', 'ment', 'ent', 'ou',
            'ism', 'ate', 'iti', 'ous', 'ive', 'ize',
        ];

        // Special case: -ion requires s or t before it
        if (str_ends_with($word, 'ion')) {
            $stem = substr($word, 0, -3);
            if (self::measure($stem) > 1 && (str_ends_with($stem, 's') || str_ends_with($stem, 't'))) {
                return $stem;
            }
        }

        foreach ($suffixes as $suffix) {
            if (str_ends_with($word, $suffix)) {
                $stem = substr($word, 0, -strlen($suffix));
                if (self::measure($stem) > 1) {
                    return $stem;
                }

                return $word;
            }
        }

        return $word;
    }

    // ──────────────────────────────────────────────
    // Step 5 — tidy up
    // ──────────────────────────────────────────────

    private static function step5(string $word): string
    {
        // 5a: remove trailing e
        if (str_ends_with($word, 'e')) {
            $stem = substr($word, 0, -1);
            if (self::measure($stem) > 1) {
                return $stem;
            }

            if (self::measure($stem) === 1 && !self::cvc($stem)) {
                return $stem;
            }
        }

        // 5b: collapse ll → l when m > 1
        if (str_ends_with($word, 'll') && self::measure(substr($word, 0, -1)) > 1) {
            return substr($word, 0, -1);
        }

        return $word;
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * Measure: the number of VC (vowel-consonant) sequences in the word.
     */
    private static function measure(string $word): int
    {
        $cvForm = self::toCvForm($word);
        // Remove leading consonants and trailing vowels
        $cvForm = preg_replace('/^c+/', '', $cvForm);
        $cvForm = preg_replace('/v+$/', '', $cvForm);

        if ($cvForm === '' || $cvForm === null) {
            return 0;
        }

        // Count vc pairs
        preg_match_all('/vc/', $cvForm, $matches);

        return count($matches[0]);
    }

    private static function containsVowel(string $word): bool
    {
        return str_contains(self::toCvForm($word), 'v');
    }

    private static function endsWithDoubleConsonant(string $word): bool
    {
        if (strlen($word) < 2) {
            return false;
        }

        $last = $word[-1];
        $secondLast = $word[-2];

        return $last === $secondLast && !self::isVowel($word, strlen($word) - 1);
    }

    /**
     * Ends with consonant-vowel-consonant where the final consonant is not w, x, or y.
     */
    private static function cvc(string $word): bool
    {
        $len = strlen($word);
        if ($len < 3) {
            return false;
        }

        $c1 = !self::isVowel($word, $len - 3);
        $v = self::isVowel($word, $len - 2);
        $c2 = !self::isVowel($word, $len - 1);

        if (!$c1 || !$v || !$c2) {
            return false;
        }

        $last = $word[$len - 1];

        return $last !== 'w' && $last !== 'x' && $last !== 'y';
    }

    /**
     * @param string[] $chars
     */
    private static function endsWith(string $word, array $chars): bool
    {
        if ($word === '') {
            return false;
        }

        return in_array($word[-1], $chars, true);
    }

    /**
     * Convert a word to its consonant/vowel form (c/v string).
     */
    private static function toCvForm(string $word): string
    {
        $result = '';
        $len = strlen($word);

        for ($i = 0; $i < $len; $i++) {
            $result .= self::isVowel($word, $i) ? 'v' : 'c';
        }

        // Collapse runs
        return (string) preg_replace('/c+/', 'c', preg_replace('/v+/', 'v', $result));
    }

    /**
     * Is the character at position $i a vowel? Y is a vowel if preceded by a consonant.
     */
    private static function isVowel(string $word, int $i): bool
    {
        $char = $word[$i] ?? '';

        if ($char === 'a' || $char === 'e' || $char === 'i' || $char === 'o' || $char === 'u') {
            return true;
        }

        if ($char === 'y') {
            return $i > 0 && !self::isVowel($word, $i - 1);
        }

        return false;
    }
}
