<?php

namespace anvildev\beacon\gql\types;

use anvildev\beacon\models\AiMarkdownOverride;
use GraphQL\Type\Definition\Type;

/**
 * Per-entry AI-markdown export override group as stored on the Beacon SEO
 * field (`aiMarkdown` sub-array).
 *
 * @phpstan-import-type GqlFieldDefinitionMap from \anvildev\beacon\gql\types\BeaconObjectType
 */
class SeoFieldAiMarkdownType extends BeaconObjectType
{
    public static function getName(): string
    {
        return 'BeaconSeoFieldAiMarkdown';
    }

    protected static function getDescription(): string
    {
        return 'Per-entry AI markdown export override stored on the Beacon SEO field.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'enabled' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Export override: inherit / include / exclude.',
                'resolve' => static function(array $source): string {
                    $raw = $source['enabled'] ?? null;
                    $allowed = [
                        AiMarkdownOverride::ENABLED_INHERIT,
                        AiMarkdownOverride::ENABLED_INCLUDE,
                        AiMarkdownOverride::ENABLED_EXCLUDE,
                    ];
                    return in_array($raw, $allowed, true) ? $raw : AiMarkdownOverride::ENABLED_INHERIT;
                },
            ],
            'customFrontMatter' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Extra YAML front-matter lines merged into the markdown export.',
                'resolve' => static fn(array $source): string => is_string($source['customFrontMatter'] ?? null)
                    ? $source['customFrontMatter']
                    : '',
            ],
        ];
    }
}
