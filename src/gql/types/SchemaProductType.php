<?php

namespace anvildev\beacon\gql\types;

use GraphQL\Type\Definition\Type;

/** @phpstan-import-type GqlFieldDefinitionMap from \anvildev\beacon\gql\types\BeaconObjectType */
class SchemaProductType extends BeaconObjectType
{
    public static function getName(): string
    {
        return 'BeaconSchemaProduct';
    }

    protected static function getDescription(): string
    {
        return 'Typed projection for schema.org Product JSON-LD nodes.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'name' => ['type' => Type::string()],
            'description' => ['type' => Type::string()],
            'sku' => ['type' => Type::string()],
            'brandName' => ['type' => Type::string()],
            'offersPrice' => ['type' => Type::string()],
            'offersCurrency' => ['type' => Type::string()],
            'offersAvailability' => ['type' => Type::string()],
        ];
    }
}
