<?php

namespace anvildev\beacon\gql\types;

use GraphQL\Type\Definition\Type;

/** @phpstan-import-type GqlFieldDefinitionMap from \anvildev\beacon\gql\types\BeaconObjectType */
class BeaconShortLinkType extends BeaconObjectType
{
    public static function getName(): string
    {
        return 'BeaconShortLink';
    }

    protected static function getDescription(): string
    {
        return 'A Beacon short / vanity link (a localized, propagating element).';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'id' => ['type' => Type::nonNull(Type::int())],
            'propagationMethod' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Which sites the slug exists on: `all`, `siteGroup`, `language`, or `none`.',
            ],
            'slug' => ['type' => Type::nonNull(Type::string())],
            'destination' => ['type' => Type::nonNull(Type::string())],
            'statusCode' => ['type' => Type::nonNull(Type::int())],
            'enabled' => ['type' => Type::nonNull(Type::boolean())],
            'clicks' => ['type' => Type::nonNull(Type::int())],
            'lastClicked' => ['type' => Type::string()],
            'expiresAt' => ['type' => Type::string()],
            'note' => ['type' => Type::string()],
            'dateCreated' => ['type' => Type::nonNull(Type::string())],
            'dateUpdated' => ['type' => Type::nonNull(Type::string())],
        ];
    }
}
