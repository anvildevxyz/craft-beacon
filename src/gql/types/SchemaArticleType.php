<?php

namespace anvildev\beacon\gql\types;

use GraphQL\Type\Definition\Type;

/** @phpstan-import-type GqlFieldDefinitionMap from \anvildev\beacon\gql\types\BeaconObjectType */
class SchemaArticleType extends BeaconObjectType
{
    public static function getName(): string
    {
        return 'BeaconSchemaArticle';
    }

    protected static function getDescription(): string
    {
        return 'Typed projection for schema.org Article-like JSON-LD nodes.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'headline' => ['type' => Type::string()],
            'description' => ['type' => Type::string()],
            'datePublished' => ['type' => Type::string()],
            'dateModified' => ['type' => Type::string()],
            'mainEntityOfPage' => ['type' => Type::string()],
        ];
    }
}
