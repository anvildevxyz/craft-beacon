<?php

namespace anvildev\beacon\gql\types;

use GraphQL\Type\Definition\Type;

/** @phpstan-import-type GqlFieldDefinitionMap from \anvildev\beacon\gql\types\BeaconObjectType */
class AlternateLinkType extends BeaconObjectType
{
    public static function getName(): string
    {
        return 'BeaconAlternateLink';
    }

    protected static function getDescription(): string
    {
        return 'hreflang alternate link pair.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'hreflang' => ['type' => Type::nonNull(Type::string())],
            'href' => ['type' => Type::nonNull(Type::string())],
        ];
    }
}
