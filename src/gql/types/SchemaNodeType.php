<?php

namespace anvildev\beacon\gql\types;

use GraphQL\Type\Definition\Type;

/** @phpstan-import-type GqlFieldDefinitionMap from \anvildev\beacon\gql\types\BeaconObjectType */
class SchemaNodeType extends BeaconObjectType
{
    public static function getName(): string
    {
        return 'BeaconSchemaNode';
    }

    protected static function getDescription(): string
    {
        return 'JSON-LD node with typed projections for common schema types.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'type' => ['type' => Type::string()],
            'rawJson' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Original node payload as JSON for unsupported or custom schema types.',
            ],
            'article' => ['type' => SchemaArticleType::getType()],
            'product' => ['type' => SchemaProductType::getType()],
            'breadcrumbList' => ['type' => SchemaBreadcrumbListType::getType()],
        ];
    }
}
