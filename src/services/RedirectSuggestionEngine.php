<?php

namespace anvildev\beacon\services;

use craft\db\Query;
use craft\db\Table;
use yii\base\Component;

/**
 * Suggests redirect targets for an unhandled 404 by scoring known entry URIs
 * against the 404'd URI. We use a cheap two-signal blend:
 *
 *  - Slug-token Jaccard overlap  — splits both URIs into `[a-z0-9]+` tokens
 *    and computes |intersection| / |union|. Catches `/2024/old-news/post` →
 *    `/news/post` (token overlap = {post}/{news,post} = 0.5).
 *
 *  - `similar_text()` percentage — PHP's built-in pairwise similarity. Handles
 *    near-equal slugs even when token sets diverge (`/abuot-us` → `/about-us`).
 *
 * Final score = 0.6 × jaccard + 0.4 × similar/100. Returns the top-N URIs.
 *
 * URI list is loaded once per request (cheap when DDEV has 100 entries, still
 * cheap at 50k — `elements_sites` is one indexed scan per site). The service
 * is stateless beyond that cache.
 */
class RedirectSuggestionEngine extends Component
{
    private const TOKEN_RE = '/[a-z0-9]+/i';

    /** @var array<int, list<string>> */
    private array $uriCache = [];

    /**
     * @return list<string>  ranked best-first, max $limit URIs (all prefixed with `/`)
     */
    public function suggestFor(string $missingUri, int $siteId, int $limit = 3): array
    {
        $candidates = $this->uriListForSite($siteId);
        if ($candidates === []) {
            return [];
        }

        $needle = ltrim($missingUri, '/');
        $needleTokens = $this->tokenise($needle);
        if ($needleTokens === []) {
            return [];
        }
        $needleSet = array_flip($needleTokens);

        $scored = [];
        foreach ($candidates as $uri) {
            $score = $this->score($needle, $needleSet, $uri);
            if ($score > 0.0) {
                $scored[] = ['uri' => '/' . ltrim($uri, '/'), 'score' => $score];
            }
        }

        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_map(static fn(array $s): string => $s['uri'], array_slice($scored, 0, $limit));
    }

    /**
     * Loads all element URIs published for the given site. Cached per-request
     * because the CP's 404 screen will call `suggestFor()` once per row, and
     * 30+ rows on the same page should not trigger 30 queries.
     *
     * @return list<string>
     */
    private function uriListForSite(int $siteId): array
    {
        if (isset($this->uriCache[$siteId])) {
            return $this->uriCache[$siteId];
        }
        /** @var list<string> $uris */
        $uris = (new Query())
            ->select(['uri'])
            ->from(Table::ELEMENTS_SITES)
            ->where(['siteId' => $siteId, 'enabled' => true])
            ->andWhere(['not', ['uri' => null]])
            ->andWhere(['not', ['uri' => '']])
            ->andWhere(['not', ['uri' => '__home__']])
            ->column();

        return $this->uriCache[$siteId] = array_values(array_unique(array_map('strval', $uris)));
    }

    /**
     * @param array<string, int> $needleSet
     */
    private function score(string $needle, array $needleSet, string $candidate): float
    {
        $candidateTokens = $this->tokenise($candidate);
        if ($candidateTokens === []) {
            return 0.0;
        }
        $candidateSet = array_flip($candidateTokens);

        $intersection = count(array_intersect_key($needleSet, $candidateSet));
        // candidate guaranteed non-empty by the early return above, so union ≥ 1.
        $union = count($needleSet + $candidateSet);
        $jaccard = $intersection / $union;

        similar_text($needle, $candidate, $percent);
        return 0.6 * $jaccard + 0.4 * ($percent / 100.0);
    }

    /**
     * @return list<string>
     */
    private function tokenise(string $value): array
    {
        // preg_match_all with a `+` quantifier never produces empty tokens.
        return preg_match_all(self::TOKEN_RE, strtolower($value), $matches) !== false
            ? $matches[0]
            : [];
    }
}
