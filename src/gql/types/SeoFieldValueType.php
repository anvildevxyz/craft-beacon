<?php

namespace anvildev\beacon\gql\types;

use GraphQL\Type\Definition\Type;

/**
 * GraphQL content type for the Beacon SEO field — the raw, per-entry
 * stored value (editor overrides), queryable with a subselection:
 *
 * ```graphql
 * entries { ... on blog_post_Entry { mySeoField { title robots { noindex } } } }
 * ```
 *
 * This intentionally mirrors the field's normalized value shape, NOT the
 * resolved output: fallbacks (site/section defaults, entry title, …) are
 * applied by `EntryInterface.beacon` (`BeaconSeoMeta`). Here `title: null`
 * means "no editor override", which is exactly what a headless CMS-admin
 * surface needs.
 *
 * @phpstan-import-type GqlFieldDefinitionMap from \anvildev\beacon\gql\types\BeaconObjectType
 */
class SeoFieldValueType extends BeaconObjectType
{
    public static function getName(): string
    {
        return 'BeaconSeoFieldValue';
    }

    protected static function getDescription(): string
    {
        return 'Raw per-entry value of a Beacon SEO field (editor overrides, before fallbacks). Use the `beacon` entry field for fully-resolved meta.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'title' => [
                'type' => Type::string(),
                'description' => 'Meta-title override; null when inheriting.',
            ],
            'description' => [
                'type' => Type::string(),
                'description' => 'Meta-description override; null when inheriting.',
            ],
            'ogImageId' => [
                'type' => Type::int(),
                'description' => 'Asset id of the Open Graph image override.',
            ],
            'canonical' => [
                'type' => Type::string(),
                'description' => 'Canonical URL override; null when inheriting.',
            ],
            'robots' => [
                'type' => Type::nonNull(SeoFieldRobotsType::getType()),
                'description' => 'Per-entry robots directive overrides.',
                'resolve' => static fn(array $source): array => is_array($source['robots'] ?? null)
                    ? $source['robots']
                    : [],
            ],
            'aiUsage' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Per-entry AI usage policy; empty string when inheriting.',
                'resolve' => static fn(array $source): string => is_scalar($source['aiUsage'] ?? null)
                    ? (string) $source['aiUsage']
                    : '',
            ],
            'schemaAddons' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(SeoFieldSchemaAddonType::getType()))),
                'description' => 'Additional JSON-LD schemas attached to this entry.',
                'resolve' => static fn(array $source): array => array_values(array_filter(
                    is_array($source['schemaAddons'] ?? null) ? $source['schemaAddons'] : [],
                    'is_array',
                )),
            ],
            'authorIds' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(Type::int()))),
                'description' => 'Ids of Beacon author elements attached to this entry.',
                'resolve' => static fn(array $source): array => array_values(array_map(
                    'intval',
                    array_filter(
                        is_array($source['authorIds'] ?? null) ? $source['authorIds'] : [],
                        'is_numeric',
                    ),
                )),
            ],
            'entities' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(SeoFieldEntityType::getType()))),
                'description' => 'Linked entities (about/mentions) for JSON-LD.',
                'resolve' => static fn(array $source): array => array_values(array_filter(
                    is_array($source['entities'] ?? null) ? $source['entities'] : [],
                    'is_array',
                )),
            ],
            'aiMarkdown' => [
                'type' => Type::nonNull(SeoFieldAiMarkdownType::getType()),
                'description' => 'AI markdown export override for this entry.',
                'resolve' => static fn(array $source): array => is_array($source['aiMarkdown'] ?? null)
                    ? $source['aiMarkdown']
                    : [],
            ],
        ];
    }
}
