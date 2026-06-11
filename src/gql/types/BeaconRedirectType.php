<?php

namespace anvildev\beacon\gql\types;

use GraphQL\Type\Definition\Type;

/** @phpstan-import-type GqlFieldDefinitionMap from \anvildev\beacon\gql\types\BeaconObjectType */
class BeaconRedirectType extends BeaconObjectType
{
    public static function getName(): string
    {
        return 'BeaconRedirect';
    }

    protected static function getDescription(): string
    {
        return 'A Beacon redirect rule (one row in `beacon_redirects`).';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'id' => ['type' => Type::nonNull(Type::int())],
            'propagationMethod' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Which sites the rule applies to: `all`, `siteGroup`, `language`, or `none`.',
            ],
            'sourceUri' => ['type' => Type::nonNull(Type::string())],
            'targetUri' => ['type' => Type::nonNull(Type::string())],
            'statusCode' => ['type' => Type::nonNull(Type::int())],
            'type' => [
                'type' => Type::nonNull(Type::string()),
                'description' => '`exact`, `glob`, or `regex` (built-in types) — third-party plugins may register others.',
            ],
            'queryStringMode' => [
                'type' => Type::nonNull(Type::string()),
                'description' => '`ignore` (default), `preserve`, or `match`.',
            ],
            'enabled' => ['type' => Type::nonNull(Type::boolean())],
            'hits' => ['type' => Type::nonNull(Type::int())],
            'lastHit' => [
                'type' => Type::string(),
                'description' => 'ISO-8601 timestamp of the most recent match, or null if never matched.',
            ],
            'source' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Origin tag: `manual`, `auto-slug`, `csv-import`, or `manual-element`.',
            ],
            'sortOrder' => ['type' => Type::nonNull(Type::int())],
            'elementId' => [
                'type' => Type::int(),
                'description' => 'Set when the rule is owned by a BeaconRedirectSourcesField on a source entry (the entry id, not the redirect id).',
            ],
            'note' => ['type' => Type::string()],
            'dateCreated' => ['type' => Type::nonNull(Type::string())],
            'dateUpdated' => ['type' => Type::nonNull(Type::string())],
        ];
    }
}
