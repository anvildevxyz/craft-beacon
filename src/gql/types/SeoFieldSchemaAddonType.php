<?php

namespace anvildev\beacon\gql\types;

use craft\helpers\Json;
use GraphQL\Type\Definition\Type;

/**
 * A per-entry additional-schema row stored on the Beacon SEO field. The
 * mapping is free-form (schema property → expression), so it's exposed as
 * a JSON-encoded string rather than a rigid object type.
 *
 * @phpstan-import-type GqlFieldDefinitionMap from \anvildev\beacon\gql\types\BeaconObjectType
 */
class SeoFieldSchemaAddonType extends BeaconObjectType
{
    public static function getName(): string
    {
        return 'BeaconSeoFieldSchemaAddon';
    }

    protected static function getDescription(): string
    {
        return 'An additional JSON-LD schema attached to the entry via the Beacon SEO field.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'type' => [
                'type' => Type::string(),
                'description' => 'The schema.org type (e.g. FAQPage, Recipe).',
                'resolve' => static fn(array $source): ?string => is_string($source['type'] ?? null)
                    ? $source['type']
                    : null,
            ],
            'mapping' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'JSON-encoded property mapping (client must JSON.parse).',
                'resolve' => static fn(array $source): string => Json::encode(
                    is_array($source['mapping'] ?? null) ? $source['mapping'] : [],
                ),
            ],
        ];
    }
}
