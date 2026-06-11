<?php

namespace anvildev\beacon\services\scoring\heuristics;

/**
 * Pure-function fact detectors for {@see \anvildev\beacon\services\scoring\FactDensityPillar}.
 * Categories: numeric assertions, dates, outbound links, named entities.
 */
final class FactDetectors
{
    /**
     * Reporting verbs for named-entity assertion detection. Shorter than the
     * HeadingClassifier list — named-entity facts skew to "is/was/launched/announced".
     */
    private const NAMED_ENTITY_VERBS = [
        'is', 'are', 'was', 'were', 'has', 'have', 'had',
        'announced', 'launched', 'released', 'reported', 'published',
        'said', 'stated', 'confirmed', 'introduced', 'unveiled',
        'shipped', 'acquired', 'raised', 'invested', 'funded',
        'plans', 'planned', 'began', 'started', 'finished',
        'sold', 'bought', 'merged', 'partners', 'partnered',
    ];

    public function countNumericAssertions(string $text): int
    {
        return count($this->matchNumericAssertions($text));
    }

    /**
     * @return list<string>
     */
    public function matchNumericAssertions(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $spans = [];

        // Reserve ISO date-shaped spans so later patterns don't shred them
        // (e.g. "2026-05-22" → "2026-05" + "22"). Sentinel value stripped at end.
        if (preg_match_all('/\b\d{4}-\d{2}(?:-\d{2})?(?:T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})?)?\b/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$value, $offset]) {
                $spans[] = [$offset, strlen($value), '__date_reservation__'];
            }
        }

        // Most-specific first (percent/currency/units) so "23" inside "23%" isn't double-counted.
        $patterns = [
            '/\b\d+(?:[.,]\d+)?\s?%/u',
            '/(?:[$€£¥₹]\s?\d+(?:[.,]\d+)?[KMB]?|\d+(?:[.,]\d+)?\s?(?:€|EUR|USD|GBP|CHF|JPY))\b/u',
            '/\b\d+(?:[.,]\d+)?\s?(?:GB|MB|KB|TB|PB|kg|g|mg|ms|µs|ns|s|min|h|km|m|cm|mm|MHz|GHz|fps|rpm|°C|°F|kWh|MWh)\b/u',
            '/\b\d+[\x{2013}-]\d+\b/u',
            '/\b\d+(?:[.,]\d+)?\s?[x×]\b/u',
        ];

        foreach ($patterns as $pattern) {
            $this->collectSpans($text, $pattern, $spans);
        }

        // Bare integers ≥ 2 (2+ digits or thousand-separated). Exclude bare years.
        if (preg_match_all('/\b(?:\d{1,3}(?:[,.]\d{3})+|\d{2,})\b/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$value, $offset]) {
                if (preg_match('/^(?:19|20)\d{2}$/', $value)) {
                    continue;
                }
                $digits = preg_replace('/\D/', '', $value) ?? '';
                if ((int) $digits < 2) {
                    continue;
                }
                if ($this->overlapsAnySpan($offset, strlen($value), $spans)) {
                    continue;
                }
                $spans[] = [$offset, strlen($value), $value];
            }
        }

        // Decimals (version numbers, multipliers, ratios) not already captured above.
        if (preg_match_all('/\b\d+\.\d+\b/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$value, $offset]) {
                if ($this->overlapsAnySpan($offset, strlen($value), $spans)) {
                    continue;
                }
                $spans[] = [$offset, strlen($value), $value];
            }
        }

        return array_values(array_filter(
            array_map(static fn($s) => $s[2], $spans),
            static fn(string $v): bool => $v !== '__date_reservation__',
        ));
    }

    public function countDateAssertions(string $text): int
    {
        return count($this->matchDateAssertions($text));
    }

    /**
     * @return list<string>
     */
    public function matchDateAssertions(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $matches = [];

        // ISO 8601 date / datetime: 2026-05-26 or 2026-05-26T12:34:56
        if (preg_match_all('/\b\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})?)?\b/u', $text, $m)) {
            $matches = [...$matches, ...$m[0]];
        }

        // English & German month names + optional day + year.
        $months = '(?:January|February|March|April|May|June|July|August|September|October|November|December|'
               . 'Januar|Februar|März|Mai|Juni|Juli|August|Oktober|Dezember)';
        if (preg_match_all('/\b(?:\d{1,2}\.?\s+)?' . $months . '\s+\d{4}\b/u', $text, $m)) {
            $matches = [...$matches, ...$m[0]];
        }

        // "since 2019", "in the 1990s", "by 2030"
        if (preg_match_all('/\b(?:since|in|by|until|from|seit|ab|bis)\s+(?:the\s+)?(?:19|20)\d{2}(?:s)?\b/iu', $text, $m)) {
            $matches = [...$matches, ...$m[0]];
        }

        // Quarter notation: Q3 2025, Q1, Q4 2024
        if (preg_match_all('/\bQ[1-4](?:\s+(?:19|20)\d{2})?\b/u', $text, $m)) {
            $matches = [...$matches, ...$m[0]];
        }

        return array_values(array_unique($matches));
    }

    /**
     * Counts outbound links only; authority weighting is handled by
     * {@see \anvildev\beacon\services\scoring\OutboundCitationDensityPillar}.
     *
     * @param list<array{href: string, isInternal: bool}> $links
     */
    public function countCitationLinks(array $links): int
    {
        return count(array_filter(
            $links,
            static fn(array $link): bool =>
                !$link['isInternal'] && $link['href'] !== '' && !str_starts_with($link['href'], '#'),
        ));
    }

    /**
     * @param list<array{0:int,1:int,2:string}> $spans
     */
    private function collectSpans(string $text, string $pattern, array &$spans): void
    {
        if (!preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
            return;
        }
        foreach ($m[0] as [$value, $offset]) {
            $len = strlen($value);
            if ($this->overlapsAnySpan($offset, $len, $spans)) {
                continue;
            }
            $spans[] = [$offset, $len, $value];
        }
    }

    /**
     * @param list<array{0:int,1:int,2:string}> $spans
     */
    private function overlapsAnySpan(int $offset, int $len, array $spans): bool
    {
        $end = $offset + $len;
        foreach ($spans as [$soff, $slen]) {
            if ($offset < ($soff + $slen) && $soff < $end) {
                return true;
            }
        }
        return false;
    }

    public function countNamedEntityAssertions(string $text): int
    {
        return count($this->matchNamedEntityAssertions($text));
    }

    /**
     * @return list<string>
     */
    public function matchNamedEntityAssertions(string $text): array
    {
        if ($text === '') {
            return [];
        }
        // Split by sentence boundaries and match per-clause to avoid runaway
        // matches across paragraph-spanning compound sentences.
        $sentences = preg_split('/(?<=[.!?])\s+/u', $text) ?: [];
        $matches = [];
        $verbAlt = implode('|', array_map(preg_quote(...), self::NAMED_ENTITY_VERBS));
        // \p{Lu} matches any uppercase Unicode letter (Über, Ñ, etc.).
        // Pattern is loop-invariant, so build it once.
        $pattern = '/\b(\p{Lu}\p{L}+(?:\s+\p{Lu}\p{L}+){0,2})\b(?:\s+\p{L}+){0,4}\s+\b(' . $verbAlt . ')\b/u';

        foreach ($sentences as $sentence) {
            if (preg_match_all($pattern, $sentence, $m)) {
                foreach ($m[0] as $i => $whole) {
                    // Exclude sentence-initial pronouns that capitalise ("It is", "He said").
                    $head = $m[1][$i];
                    if (in_array($head, [
                        // English pronouns & determiners that capitalise sentence-initially
                        'It', 'He', 'She', 'They', 'We', 'You', 'I', 'This', 'That', 'These', 'Those',
                        'Everyone', 'Everybody', 'Someone', 'Somebody', 'Nobody', 'Anyone', 'Anybody',
                        'Each', 'Every', 'All', 'Some', 'Any', 'No', 'None',
                        'Our', 'Their', 'My', 'Your', 'His', 'Her', 'Its',
                        // German equivalents
                        'Er', 'Sie', 'Es', 'Wir', 'Ihr', 'Das', 'Dies', 'Jeder', 'Jede', 'Alle', 'Niemand',
                    ], true)) {
                        continue;
                    }
                    $matches[] = $whole;
                }
            }
        }
        return array_values(array_unique($matches));
    }
}
