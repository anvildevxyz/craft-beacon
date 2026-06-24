<?php

namespace anvildev\beacon\services\entities;

/**
 * Seam over the Wikidata HTTP API so {@see \anvildev\beacon\services\WikidataService}
 * can be unit-tested with a fake that never hits the network.
 *
 * @phpstan-import-type PickerRow from WikidataResultParser
 */
interface WikidataClientInterface
{
    /**
     * Search Wikidata entities matching $query, enriched with the Wikipedia
     * sitelink and official-website URL where available. Implementations must
     * throw on transport failure; the service layer turns that into a graceful
     * empty result.
     *
     * @return list<PickerRow>
     */
    public function search(string $query, string $language, int $limit): array;
}
