<?php

namespace anvildev\beacon\gql\types;

use GraphQL\Type\Definition\Type;

/** @phpstan-import-type GqlFieldDefinitionMap from \anvildev\beacon\gql\types\BeaconObjectType */
class BeaconRedirect404Type extends BeaconObjectType
{
    public static function getName(): string
    {
        return 'BeaconRedirect404';
    }

    protected static function getDescription(): string
    {
        return 'An unhandled 404 captured by Beacon (one row in `beacon_redirect_404_log`).';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'id' => ['type' => Type::nonNull(Type::int())],
            'siteId' => ['type' => Type::nonNull(Type::int())],
            'uri' => ['type' => Type::nonNull(Type::string())],
            'hits' => ['type' => Type::nonNull(Type::int())],
            'firstSeen' => ['type' => Type::nonNull(Type::string())],
            'lastSeen' => ['type' => Type::nonNull(Type::string())],
            'referer' => ['type' => Type::string()],
            'handled' => ['type' => Type::nonNull(Type::boolean())],
        ];
    }
}
