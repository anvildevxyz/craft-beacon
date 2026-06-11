<?php

namespace anvildev\beacon\gql\types;

use GraphQL\Type\Definition\Type;

/**
 * @phpstan-import-type GqlFieldDefinitionMap from \anvildev\beacon\gql\types\BeaconObjectType
 */
class SchemaListItemType extends BeaconObjectType
{
    public static function getName(): string
    {
        return 'BeaconSchemaListItem';
    }

    protected static function getDescription(): string
    {
        return 'Structured ListItem used by BreadcrumbList JSON-LD.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'position' => ['type' => Type::int()],
            'name' => ['type' => Type::string()],
            'item' => ['type' => Type::string()],
        ];
    }
}
