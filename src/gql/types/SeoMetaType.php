<?php

namespace anvildev\beacon\gql\types;

use anvildev\beacon\gql\resolvers\GeoScoreGqlResolver;
use GraphQL\Type\Definition\Type;

/**
 * @phpstan-import-type GqlFieldDefinitionMap from \anvildev\beacon\gql\types\BeaconObjectType
 */
class SeoMetaType extends BeaconObjectType
{
    public static function getName(): string
    {
        return 'BeaconSeoMeta';
    }

    protected static function getDescription(): string
    {
        return 'Beacon SEO metadata for an entry.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'title' => ['type' => Type::nonNull(Type::string())],
            'description' => ['type' => Type::nonNull(Type::string())],
            'canonical' => ['type' => Type::string()],
            'robots' => ['type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string())))],
            'articlePublishedTime' => [
                'type' => Type::string(),
                'description' => 'ISO-8601 `article:published_time` when the entry is an article.',
            ],
            'articleModifiedTime' => [
                'type' => Type::string(),
                'description' => 'ISO-8601 `article:modified_time` when available.',
            ],
            'alternates' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(AlternateLinkType::getType()))),
                'description' => 'hreflang alternates when configured (see `beacon.hreflang`).',
            ],
            'breadcrumbs' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(BreadcrumbItemType::getType()))),
                'description' => 'Resolved breadcrumb trail (same source as `craft.beacon.breadcrumbs()`).',
            ],
            'openGraph' => ['type' => OpenGraphType::getType()],
            'twitter' => ['type' => TwitterCardType::getType()],
            'schemas' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
                'description' => 'Array of JSON-LD schema strings (client must JSON.parse each).',
            ],
            'schemaNodes' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(SchemaNodeType::getType()))),
                'description' => 'Structured JSON-LD with typed projections for Article, Product, and BreadcrumbList plus raw JSON passthrough.',
            ],
            'geoScore' => [
                'type' => BeaconGeoScoreType::getType(),
                'description' => 'Composite GEO score (0–100) with per-pillar breakdown. Requires the `beaconGeoScore:read` schema component on the token; returns null without it, or when no score row exists yet for this (element, site).',
                'resolve' => GeoScoreGqlResolver::resolve(...),
            ],
        ];
    }
}
