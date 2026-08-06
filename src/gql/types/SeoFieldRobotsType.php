<?php

namespace anvildev\beacon\gql\types;

use GraphQL\Type\Definition\Type;

/**
 * Raw per-entry robots directives as stored on the Beacon SEO field —
 * the editor's input, before site/section defaults are merged in. The
 * resolved directive list (what actually renders) stays on
 * `BeaconSeoMeta.robots`.
 *
 * Stored keys use the directive spelling (`max-snippet`,
 * `unavailable_after`); GraphQL names are camelCased, so the value-typed
 * directives resolve through explicit key maps below.
 *
 * @phpstan-import-type GqlFieldDefinitionMap from \anvildev\beacon\gql\types\BeaconObjectType
 */
class SeoFieldRobotsType extends BeaconObjectType
{
    public static function getName(): string
    {
        return 'BeaconSeoFieldRobots';
    }

    protected static function getDescription(): string
    {
        return 'Per-entry robots directive overrides stored on the Beacon SEO field.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        $bool = static fn(string $key): array => [
            'type' => Type::nonNull(Type::boolean()),
            'resolve' => static fn(array $source): bool => !empty($source[$key]),
        ];
        // Value-typed directives keep their raw stored string; empty string
        // (directive not set) resolves to null.
        $rawString = static fn(string $key): array => [
            'type' => Type::string(),
            'resolve' => static function(array $source) use ($key): ?string {
                $value = $source[$key] ?? null;
                if (!is_scalar($value) || (string) $value === '') {
                    return null;
                }
                return (string) $value;
            },
        ];

        return [
            'noindex' => $bool('noindex'),
            'nofollow' => $bool('nofollow'),
            'noarchive' => $bool('noarchive'),
            'nosnippet' => $bool('nosnippet'),
            'noimageindex' => $bool('noimageindex'),
            'notranslate' => $bool('notranslate'),
            'indexifembedded' => $bool('indexifembedded'),
            'maxSnippet' => $rawString('max-snippet') + [
                'description' => 'Raw `max-snippet` value (e.g. "160" or "-1"); null when unset.',
            ],
            'maxImagePreview' => $rawString('max-image-preview') + [
                'description' => 'Raw `max-image-preview` value (none / standard / large); null when unset.',
            ],
            'maxVideoPreview' => $rawString('max-video-preview') + [
                'description' => 'Raw `max-video-preview` value in seconds; null when unset.',
            ],
            'unavailableAfter' => $rawString('unavailable_after') + [
                'description' => 'Raw `unavailable_after` datetime string; null when unset.',
            ],
        ];
    }
}
