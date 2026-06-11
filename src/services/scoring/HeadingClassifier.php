<?php

namespace anvildev\beacon\services\scoring;

/**
 * Classifies a heading string as "claim-shaped" (a complete statement an
 * AI engine can quote as a self-contained answer) vs. "topic-shaped" (a
 * noun phrase that requires further context). Pure heuristic — extracted
 * from {@see ClaimBasedHeadingsPillar} so the rules can be unit-tested
 * without a Craft bootstrap.
 *
 * A heading is claim-shaped when:
 *
 *   1. it is at least {@see self::MIN_TOKENS} words long, **and**
 *   2. it contains at least one finite verb / modal / copula from the
 *      site-language stem list.
 *
 * The verb stem lists are deliberately small (~30 entries each). Non-English
 * sites fall back to the English set except `de*`, which uses the German list.
 */
final class HeadingClassifier
{
    private const MIN_TOKENS = 4;

    /**
     * Bare stems; the matcher appends common suffixes (-s/-es/-ed/-d/-ing/-ies/-ied).
     * Auxiliaries/modals appear as-is since they don't conjugate the same way.
     */
    private const VERB_STEMS_EN = [
        'is', 'are', 'was', 'were', 'be', 'been', 'being', 'am',
        'has', 'have', 'had', 'do', 'does', 'did',
        'will', 'would', 'can', 'could', 'shall', 'should',
        'must', 'may', 'might',
        'need', 'get', 'run', 'work', 'fail', 'break', 'cost', 'save', 'cut',
        'reduce', 'improve', 'require', 'provide', 'ship', 'launch', 'power',
        'beat', 'win', 'return', 'match', 'fix', 'solve', 'close',
        'help', 'make', 'use', 'show', 'tell', 'find', 'know',
        'mean', 'see', 'give', 'take', 'keep', 'stop', 'start', 'finish',
        'speed', 'slow', 'limit', 'enable', 'disable',
        'cite', 'rank', 'serve', 'cache', 'log', 'track', 'add', 'remove',
        'support', 'replace', 'expose', 'block', 'allow', 'deny',
    ];

    private const VERB_STEMS_DE = [
        'ist', 'sind', 'war', 'waren', 'bin', 'bist', 'sein', 'gewesen',
        'hat', 'haben', 'hatte', 'hatten',
        'tut', 'tat', 'tun',
        'wird', 'wurde', 'werden',
        'kann', 'konnte', 'könnte', 'kannst', 'können',
        'soll', 'sollte', 'sollen',
        'muss', 'musste', 'müssen',
        'darf', 'durfte', 'dürfen',
        'mag', 'mochte', 'mögen',
        'lauf', 'läuf', 'lief', 'funktionier',
        'spar', 'kost', 'reduzier', 'verbesser', 'erforder',
        'lieferr', 'liefer', 'starte', 'gewinn', 'schlag',
        'lös', 'beheb', 'schliess', 'beend',
        'mach', 'arbeit', 'hilft', 'helf', 'zeig', 'find',
        'beschreib', 'erklär', 'verhinder', 'verbinde',
    ];

    private readonly string $languageTag;

    public function __construct(string $language = 'en')
    {
        // Normalise BCP-47 to primary subtag (de-CH → de).
        $primary = strtolower(trim(explode('-', $language)[0] ?? 'en'));
        $this->languageTag = $primary !== '' ? $primary : 'en';
    }

    public function isClaim(string $headingText): bool
    {
        $tokens = $this->tokens($headingText);
        if (count($tokens) < self::MIN_TOKENS) {
            return false;
        }
        return $this->containsVerb($tokens);
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        // Normalise whitespace and split in one preg_split pass.
        $rawTokens = preg_split('/\s+/u', $text) ?: [];
        $cleaned = [];
        foreach ($rawTokens as $token) {
            $stripped = preg_replace('/[^\p{L}\p{N}_\']/u', '', $token) ?? '';
            if ($stripped !== '') {
                $cleaned[] = mb_strtolower($stripped);
            }
        }
        return $cleaned;
    }

    /**
     * @param list<string> $tokens
     */
    private function containsVerb(array $tokens): bool
    {
        $stems = $this->languageTag === 'de' ? self::VERB_STEMS_DE : self::VERB_STEMS_EN;
        foreach ($tokens as $token) {
            foreach ($stems as $stem) {
                if ($this->tokenMatchesStem($token, $stem)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Accepts the bare stem or stem + common suffix. False positives (`reducer`
     * matching `reduce`) are acceptable — the cost is one heading mis-classified
     * as a claim, not a scoring direction error.
     */
    private function tokenMatchesStem(string $token, string $stem): bool
    {
        if ($token === $stem) {
            return true;
        }
        if (!str_starts_with($token, $stem)) {
            return false;
        }
        $suffix = substr($token, strlen($stem));
        $allowed = $this->languageTag === 'de'
            ? ['t', 'en', 'te', 'ten', 'st', 'et']
            : ['s', 'es', 'ed', 'd', 'ing', 'ies', 'ied'];
        return in_array($suffix, $allowed, true);
    }
}
