<?php

namespace anvildev\beacon\services\entities;

use Craft;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Live Wikidata transport. Issues two batched calls per search —
 * `wbsearchentities` for candidate QIDs, then a single `wbgetentities` for
 * their Wikipedia sitelinks + official-website claims — and delegates all
 * shape-handling to {@see WikidataResultParser}.
 *
 * @phpstan-import-type PickerRow from WikidataResultParser
 */
final class HttpWikidataClient implements WikidataClientInterface
{
    private const ENDPOINT = 'https://www.wikidata.org/w/api.php';

    public function search(string $query, string $language, int $limit): array
    {
        $client = Craft::createGuzzleClient(['timeout' => 8]);

        try {
            $searchResponse = $client->get(self::ENDPOINT, [
                'query' => [
                    'action' => 'wbsearchentities',
                    'search' => $query,
                    'language' => $language,
                    'uselang' => $language,
                    'format' => 'json',
                    'limit' => $limit,
                    'type' => 'item',
                ],
                'http_errors' => false,
            ]);
            $searchJson = $this->decode((string) $searchResponse->getBody());

            $ids = $this->idsFrom($searchJson);
            $entitiesJson = [];
            if ($ids !== []) {
                $entitiesResponse = $client->get(self::ENDPOINT, [
                    'query' => [
                        'action' => 'wbgetentities',
                        'ids' => implode('|', $ids),
                        'props' => 'sitelinks/urls|claims',
                        'languages' => $language,
                        'format' => 'json',
                    ],
                    'http_errors' => false,
                ]);
                $entitiesJson = $this->decode((string) $entitiesResponse->getBody());
            }
        } catch (GuzzleException $e) {
            throw new WikidataException('Wikidata request failed: ' . $e->getMessage(), 0, $e);
        }

        return WikidataResultParser::parse($searchJson, $entitiesJson, $language);
    }

    /**
     * @param array<string,mixed> $searchJson
     * @return list<string>
     */
    private function idsFrom(array $searchJson): array
    {
        $search = $searchJson['search'] ?? null;
        if (!is_array($search)) {
            return [];
        }
        $ids = [];
        foreach ($search as $hit) {
            if (is_array($hit) && is_string($hit['id'] ?? null) && $hit['id'] !== '') {
                $ids[] = $hit['id'];
            }
        }
        return $ids;
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }
}
