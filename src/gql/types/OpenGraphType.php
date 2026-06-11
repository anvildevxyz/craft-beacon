<?php

namespace anvildev\beacon\gql\types;

use GraphQL\Type\Definition\Type;

/** @phpstan-import-type GqlFieldDefinitionMap from \anvildev\beacon\gql\types\BeaconObjectType */
class OpenGraphType extends BeaconObjectType
{
    public static function getName(): string
    {
        return 'BeaconOpenGraph';
    }

    protected static function getDescription(): string
    {
        return 'Open Graph metadata.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'title' => ['type' => Type::string()],
            'description' => ['type' => Type::string()],
            'image' => ['type' => Type::string()],
            'type' => ['type' => Type::string()],
            'siteName' => ['type' => Type::string()],
            'url' => ['type' => Type::string()],
            'imageWidth' => ['type' => Type::int()],
            'imageHeight' => ['type' => Type::int()],
            'imageAlt' => ['type' => Type::string()],
        ];
    }
}
