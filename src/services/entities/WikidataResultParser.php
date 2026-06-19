<?php

namespace anvildev\beacon\services\entities;

/**
 * Pure transform from raw Wikidata API JSON into the picker rows the SEO
 * field stores. Kept dependency-free so it's exhaustively unit-testable
 * without touching the network — {@see HttpWikidataClient} does the fetching
 * and hands the decoded arrays here.
 *
 * @phpstan-type PickerRow array{
 *     qid: string,
 *     label: string,
 *     description: string,
 *     wikidataUrl: string,
 *     wikipediaUrl: string,
 *     officialUrl: string,
 * }
 */
final class WikidataResultParser
{
    /**
     * @param array<string,mixed> $searchJson decoded `wbsearchentities` response
     * @param array<string,mixed> $entitiesJson decoded `wbgetentities` response (may be empty)
     * @param string $language wiki language code used for the Wikipedia sitelink
     * @return list<PickerRow>
     */
    public static function parse(array $searchJson, array $entitiesJson, string $language): array
    {
        $search = $searchJson['search'] ?? null;
        if (!is_array($search)) {
            return [];
        }

        $entities = is_array($entitiesJson['entities'] ?? null) ? $entitiesJson['entities'] : [];

        $rows = [];
        foreach ($search as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $qid = self::str($hit['id'] ?? '');
            if ($qid === '') {
                continue;
            }

            $detail = is_array($entities[$qid] ?? null) ? $entities[$qid] : [];

            $rows[] = [
                'qid' => $qid,
                'label' => self::str($hit['label'] ?? '') ?: $qid,
                'description' => self::str($hit['description'] ?? ''),
                'wikidataUrl' => 'https://www.wikidata.org/wiki/' . $qid,
                'wikipediaUrl' => self::wikipediaUrl($detail, $language),
                'officialUrl' => self::officialUrl($detail),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $detail one entity from `wbgetentities`
     */
    private static function wikipediaUrl(array $detail, string $language): string
    {
        $sitelinks = $detail['sitelinks'] ?? null;
        if (!is_array($sitelinks)) {
            return '';
        }
        $link = $sitelinks[$language . 'wiki'] ?? null;
        if (is_array($link) && is_string($link['url'] ?? null) && $link['url'] !== '') {
            return $link['url'];
        }
        return '';
    }

    /**
     * Pull the official-website claim (property P856) if present.
     *
     * @param array<string,mixed> $detail one entity from `wbgetentities`
     */
    private static function officialUrl(array $detail): string
    {
        $claims = $detail['claims'] ?? null;
        if (!is_array($claims) || !is_array($claims['P856'] ?? null)) {
            return '';
        }
        foreach ($claims['P856'] as $claim) {
            $value = $claim['mainsnak']['datavalue']['value'] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return '';
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
