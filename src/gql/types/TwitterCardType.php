<?php

namespace anvildev\beacon\gql\types;

use GraphQL\Type\Definition\Type;

/** @phpstan-import-type GqlFieldDefinitionMap from \anvildev\beacon\gql\types\BeaconObjectType */
class TwitterCardType extends BeaconObjectType
{
    public static function getName(): string
    {
        return 'BeaconTwitterCard';
    }

    protected static function getDescription(): string
    {
        return 'Twitter card metadata.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'card' => ['type' => Type::string()],
            'title' => ['type' => Type::string()],
            'description' => ['type' => Type::string()],
            'image' => ['type' => Type::string()],
            'site' => ['type' => Type::string()],
        ];
    }
}
