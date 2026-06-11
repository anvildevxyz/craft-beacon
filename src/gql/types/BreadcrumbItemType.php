<?php

namespace anvildev\beacon\gql\types;

use GraphQL\Type\Definition\Type;

/** @phpstan-import-type GqlFieldDefinitionMap from \anvildev\beacon\gql\types\BeaconObjectType */
class BreadcrumbItemType extends BeaconObjectType
{
    public static function getName(): string
    {
        return 'BeaconBreadcrumbItem';
    }

    protected static function getDescription(): string
    {
        return 'Resolved breadcrumb item.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'name' => ['type' => Type::nonNull(Type::string())],
            'url' => ['type' => Type::string()],
        ];
    }
}
