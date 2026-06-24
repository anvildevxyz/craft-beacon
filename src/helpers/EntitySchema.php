<?php

namespace anvildev\beacon\helpers;

/**
 * Turns the Beacon SEO field's stored `entities` list into Schema.org
 * `about` / `mentions` nodes, and sanitises incoming field values.
 *
 * Each stored entity binds a topic the page is about (a person, place,
 * organization, concept) to authoritative identifiers — a Wikidata QID and,
 * where available, the matching Wikipedia article and official website — so
 * AI engines and knowledge graphs can disambiguate "which Mercury / Apple /
 * Marie Curie" the page means. `role` decides whether the entity is the
 * page's primary subject (`about`) or merely referenced (`mentions`).
 *
 * @phpstan-type EntityRow array{
 *     qid: string,
 *     label: string,
 *     description: string,
 *     wikidataUrl: string,
 *     wikipediaUrl: string,
 *     officialUrl: string,
 *     role: 'about'|'mentions',
 * }
 */
final class EntitySchema
{
    public const ROLE_ABOUT = 'about';
    public const ROLE_MENTIONS = 'mentions';

    /**
     * Coerce raw field input (POST array or decoded JSON) into a clean list of
     * entity rows. Drops rows without a label or any linkable URL, clamps the
     * role to a known value, and trims every string.
     *
     * @return list<EntityRow>
     */
    public static function sanitize(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $rows = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $label = self::str($entry['label'] ?? '');
            if ($label === '') {
                continue;
            }

            $wikidataUrl = self::str($entry['wikidataUrl'] ?? '');
            $wikipediaUrl = self::str($entry['wikipediaUrl'] ?? '');
            $officialUrl = self::str($entry['officialUrl'] ?? '');
            $qid = self::str($entry['qid'] ?? '');

            // A row must resolve to at least one external identifier, otherwise
            // it carries no signal worth emitting.
            if ($wikidataUrl === '' && $wikipediaUrl === '' && $officialUrl === '') {
                continue;
            }

            $role = self::str($entry['role'] ?? self::ROLE_ABOUT);
            if ($role !== self::ROLE_ABOUT && $role !== self::ROLE_MENTIONS) {
                $role = self::ROLE_ABOUT;
            }

            $rows[] = [
                'qid' => $qid,
                'label' => $label,
                'description' => self::str($entry['description'] ?? ''),
                'wikidataUrl' => $wikidataUrl,
                'wikipediaUrl' => $wikipediaUrl,
                'officialUrl' => $officialUrl,
                'role' => $role,
            ];
        }

        return $rows;
    }

    /**
     * Build the `about` and `mentions` arrays for an entry's primary schema
     * node from its stored entity list. Returns only the keys (`about` and/or
     * `mentions`) that have at least one node, so callers can merge the result
     * without emitting empty arrays.
     *
     * @return array<string, list<array<string,mixed>>>
     */
    public static function nodesFor(mixed $entities): array
    {
        $about = [];
        $mentions = [];

        foreach (self::sanitize($entities) as $entity) {
            $node = self::nodeFor($entity);
            if ($node === null) {
                continue;
            }
            if ($entity['role'] === self::ROLE_MENTIONS) {
                $mentions[] = $node;
            } else {
                $about[] = $node;
            }
        }

        $out = [];
        if ($about !== []) {
            $out['about'] = $about;
        }
        if ($mentions !== []) {
            $out['mentions'] = $mentions;
        }
        return $out;
    }

    /**
     * @param EntityRow $entity
     * @return array<string,mixed>|null
     */
    private static function nodeFor(array $entity): ?array
    {
        $sameAs = array_values(array_filter(
            [$entity['wikidataUrl'], $entity['wikipediaUrl'], $entity['officialUrl']],
            static fn(string $url): bool => $url !== '',
        ));
        if ($sameAs === []) {
            return null;
        }

        $node = [
            '@type' => 'Thing',
            'name' => $entity['label'],
        ];

        $id = $entity['wikidataUrl'] !== '' ? $entity['wikidataUrl'] : $sameAs[0];
        $node['@id'] = $id;
        $node['sameAs'] = $sameAs;

        return $node;
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
