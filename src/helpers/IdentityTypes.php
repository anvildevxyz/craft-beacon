<?php

namespace anvildev\beacon\helpers;

use Craft;

/**
 * The schema.org `@type` options offered for the site identity (Settings →
 * Organization). A curated set — `Person` plus the Organization subtypes most
 * useful for site identity / Knowledge Graph — rather than the full ~900-type
 * hierarchy, which would overwhelm the picker. The chosen value is emitted
 * verbatim as the identity node's `@type`.
 */
final class IdentityTypes
{
    /**
     * value (schema.org type) => human label.
     *
     * @var array<string, string>
     */
    private const TYPES = [
        'Organization' => 'Organization (general)',
        'Corporation' => 'Corporation',
        'LocalBusiness' => 'Local business',
        'OnlineStore' => 'Online store',
        'NewsMediaOrganization' => 'News / media organization',
        'EducationalOrganization' => 'Educational organization',
        'GovernmentOrganization' => 'Government organization',
        'NGO' => 'Non-governmental organization (NGO)',
        'Person' => 'Person',
    ];

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return array_map(static fn(string $label): string => Craft::t('beacon', $label), self::TYPES);
    }

    public static function isValid(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /**
     * Returns the type when valid, otherwise the safe default `Organization`.
     */
    public static function normalize(string $type): string
    {
        return self::isValid($type) ? $type : 'Organization';
    }
}
