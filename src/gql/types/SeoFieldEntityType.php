<?php

namespace anvildev\beacon\gql\types;

use GraphQL\Type\Definition\Type;

/**
 * A linked-entity row stored on the Beacon SEO field. Rows are sanitized
 * on normalize ({@see \anvildev\beacon\helpers\EntitySchema::sanitize()}),
 * so every key exists as a string; resolvers still fall back to '' for
 * defense against pre-sanitize legacy data.
 *
 * @phpstan-import-type GqlFieldDefinitionMap from \anvildev\beacon\gql\types\BeaconObjectType
 */
class SeoFieldEntityType extends BeaconObjectType
{
    public static function getName(): string
    {
        return 'BeaconSeoFieldEntity';
    }

    protected static function getDescription(): string
    {
        return 'A linked entity (about/mentions) stored on the Beacon SEO field.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        $string = static fn(string $key, string $description): array => [
            'type' => Type::nonNull(Type::string()),
            'description' => $description,
            'resolve' => static fn(array $source): string => is_scalar($source[$key] ?? null)
                ? (string) $source[$key]
                : '',
        ];

        return [
            'qid' => $string('qid', 'Wikidata QID (may be empty for manual rows).'),
            'label' => $string('label', 'Display label.'),
            'description' => $string('description', 'Short description.'),
            'wikidataUrl' => $string('wikidataUrl', 'Wikidata URL; empty when not set.'),
            'wikipediaUrl' => $string('wikipediaUrl', 'Wikipedia URL; empty when not set.'),
            'officialUrl' => $string('officialUrl', 'Official website URL; empty when not set.'),
            'role' => $string('role', 'Schema role: about or mentions.'),
        ];
    }
}
