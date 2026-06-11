<?php

namespace anvildev\beacon\gql\types;

use GraphQL\Type\Definition\Type;

/** @phpstan-import-type GqlFieldDefinitionMap from \anvildev\beacon\gql\types\BeaconObjectType */
class SchemaBreadcrumbListType extends BeaconObjectType
{
    public static function getName(): string
    {
        return 'BeaconSchemaBreadcrumbList';
    }

    protected static function getDescription(): string
    {
        return 'Typed projection for schema.org BreadcrumbList JSON-LD nodes.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'itemListElement' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(SchemaListItemType::getType()))),
            ],
        ];
    }
}
