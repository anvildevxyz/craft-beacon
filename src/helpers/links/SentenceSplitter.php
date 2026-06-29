<?php

namespace anvildev\beacon\helpers\links;

/**
 * Splits text into sentences while preserving common abbreviations
 * (honorifics, initials, Latin shorthand) that would otherwise be
 * misread as sentence boundaries.
 */
final class SentenceSplitter
{
    /** @var list<string> */
    private const ABBREVIATIONS = [
        'Mr.', 'Mrs.', 'Ms.', 'Mx.', 'Dr.', 'Prof.', 'Rev.', 'Sr.', 'Jr.', 'St.',
        'Inc.', 'Ltd.', 'Corp.', 'Co.', 'No.', 'vs.', 'etc.', 'e.g.', 'i.e.',
    ];

    /**
     * @return list<string>
     */
    public static function split(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $placeholderToOriginal = [];
        $counter = 0;

        // Mask known abbreviations (case-insensitive match, original casing preserved)
        foreach (self::ABBREVIATIONS as $abbr) {
            $escaped = preg_quote($abbr, '/');
            $text = preg_replace_callback(
                '/' . $escaped . '/i',
                function(array $match) use (&$placeholderToOriginal, &$counter): string {
                    $placeholder = "\x00A" . ($counter++) . "\x00";
                    $placeholderToOriginal[$placeholder] = $match[0];
                    return $placeholder;
                },
                $text
            ) ?? $text;
        }

        // Mask single-letter initials like U.S., A.M., J.R.R.
        $text = preg_replace_callback(
            '/\b(?:[A-Z]\.)+/',
            function(array $match) use (&$placeholderToOriginal, &$counter): string {
                $placeholder = "\x00I" . ($counter++) . "\x00";
                $placeholderToOriginal[$placeholder] = $match[0];
                return $placeholder;
            },
            $text
        ) ?? $text;

        // Split on sentence-terminating punctuation followed by whitespace
        $parts = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Restore masked abbreviations
        return array_values(array_map(
            static fn(string $s): string => strtr($s, $placeholderToOriginal),
            $parts
        ));
    }
}
