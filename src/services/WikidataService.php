<?php

namespace anvildev\beacon\services;

use anvildev\beacon\services\entities\HttpWikidataClient;
use anvildev\beacon\services\entities\WikidataClientInterface;
use Craft;
use yii\base\Component;

/**
 * Backs the SEO field's entity picker: searches Wikidata for entities the
 * editor can bind to a page, caches each query, and degrades to an empty
 * result when the API is unreachable so the editor never breaks.
 *
 * Wire-up: registered as `Plugin::$plugin->wikidata`. Tests inject a fake
 * {@see WikidataClientInterface} via {@see self::$client}; in production the
 * live {@see HttpWikidataClient} is built on demand.
 *
 * @phpstan-import-type PickerRow from \anvildev\beacon\services\entities\WikidataResultParser
 */
class WikidataService extends Component
{
    /** Cache TTL for a search query, in seconds. */
    public int $cacheDuration = 86400;

    /**
     * Test seam: inject a fake client so unit tests never hit the network.
     * When null, the live HTTP client is used.
     */
    public ?WikidataClientInterface $client = null;

    /**
     * Cached search for entities matching $query. Returns at most $limit picker
     * rows. Empty/short queries and any transport failure resolve to an empty
     * list (logged) rather than throwing. Results are cached per query so the
     * picker doesn't hammer the Wikidata API on every keystroke.
     *
     * @return list<PickerRow>
     */
    public function search(string $query, string $language = 'en', int $limit = 7): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $language = $this->normalizeLanguage($language);
        $limit = max(1, min(20, $limit));

        $cacheKey = sprintf('beacon:wikidata:%s:%s:%d', $language, md5($query), $limit);
        $cache = Craft::$app->getCache();
        $cached = $cache->get($cacheKey);
        if (is_array($cached)) {
            /** @var list<PickerRow> $cached */
            return $cached;
        }

        $results = $this->fetch($query, $language, $limit);
        $cache->set($cacheKey, $results, $this->cacheDuration);
        return $results;
    }

    /**
     * Uncached search straight to the client. Holds the graceful-degradation
     * contract: short queries and any transport failure return an empty list
     * rather than throwing. Public so it's exercisable without a cache backend.
     *
     * @return list<PickerRow>
     */
    public function fetch(string $query, string $language = 'en', int $limit = 7): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        try {
            return $this->resolveClient()->search($query, $this->normalizeLanguage($language), max(1, min(20, $limit)));
        } catch (\Throwable $e) {
            Craft::warning('Beacon Wikidata search failed: ' . $e->getMessage(), 'beacon');
            return [];
        }
    }

    private function resolveClient(): WikidataClientInterface
    {
        return $this->client ??= new HttpWikidataClient();
    }

    /**
     * Reduce a Craft site language (e.g. `en-GB`) to a wiki language code
     * (`en`). Falls back to English for empty input.
     */
    private function normalizeLanguage(string $language): string
    {
        $base = strtolower(trim(explode('-', $language)[0] ?? ''));
        return preg_match('/^[a-z]{2,3}$/', $base) === 1 ? $base : 'en';
    }
}
