<?php

namespace anvildev\beacon\gql\types;

use anvildev\beacon\models\Redirect;
use GraphQL\Type\Definition\Type;

/**
 * A redirect resolved for a concrete request URI via the full matcher
 * (exact / glob / regex / custom). Unlike `BeaconRedirect` — a raw rule
 * row — this carries `resolvedTarget`: capture groups substituted and the
 * query string applied per the rule's mode, i.e. the URL a headless
 * frontend should actually redirect to.
 *
 * Source: {@see \anvildev\beacon\models\Redirect}.
 *
 * @phpstan-import-type GqlFieldDefinitionMap from \anvildev\beacon\gql\types\BeaconObjectType
 */
class BeaconResolvedRedirectType extends BeaconObjectType
{
    public static function getName(): string
    {
        return 'BeaconResolvedRedirect';
    }

    protected static function getDescription(): string
    {
        return 'A redirect rule resolved against a concrete request URI; redirect to `resolvedTarget` with `statusCode`.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'Id of the matched redirect rule.',
            ],
            'sourceUri' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'The rule\'s source pattern as configured.',
            ],
            'targetUri' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'The rule\'s raw target (may contain $1 capture placeholders).',
            ],
            'resolvedTarget' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'The final target for this URI — captures substituted, query string applied.',
            ],
            'statusCode' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'HTTP status code to redirect with (301/302/…).',
            ],
            'type' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Matcher type: exact / glob / regex, or a custom matcher handle.',
            ],
            'queryStringMode' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Query-string handling mode: ignore / preserve / match.',
                'resolve' => static fn(Redirect $source): string => $source->queryStringMode->value,
            ],
        ];
    }
}
