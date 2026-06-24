<?php

namespace anvildev\beacon\helpers;

use Craft;
use craft\elements\Entry;
use craft\helpers\UrlHelper;

/**
 * Resolves CP deep links for GEO pillar feedback notes shown in the drill-down.
 * Matches rendered note text against known translation keys in the active locale.
 */
final class GeoScoreDrillDownNoteLink
{
    /** @var array<string, string> translation key → CP route (passed to cpUrl) */
    private const SETTINGS_ROUTES = [
        'geo.pillar.entityCompleteness.set.site.organization.name.settings' => 'beacon/settings/organization',
        'geo.pillar.entityCompleteness.add.organization.logo.asset.richer' => 'beacon/settings/organization',
        'geo.pillar.entityCompleteness.organization.sameAs.threshold' => 'beacon/settings/organization',
        'geo.pillar.outboundCitation.unclassified.hosts' => 'beacon/settings/geo',
    ];

    /** @var array<string, string> */
    private const AUTHORS_ROUTES = [
        'geo.pillar.entityCompleteness.author.attached.but.no.external' => 'beacon/authors',
    ];

    /** @var list<string> */
    private const ENTRY_EDIT_KEYS = [
        'geo.pillar.entityCompleteness.attach.author.entry.author.person',
        'geo.pillar.chunkability.no.h2.sections.found.break',
        'geo.pillar.claimHeadings.no.h2.h3.subheadings.found',
        'geo.pillar.factDensity.no.content.found.score.add',
        'geo.pillar.factDensity.content.too.short.words.score',
        'geo.pillar.outboundCitation.no.content.found.score',
        'geo.pillar.outboundCitation.content.too.short.words.score',
        'geo.pillar.outboundCitation.no.authoritative.sources',
        'geo.pillar.outboundCitation.low.authority.density',
        'geo.pillar.chunkability.short.lead',
        'geo.pillar.chunkability.long.lead',
        'geo.pillar.chunkability.stacked.headings',
        'geo.pillar.claimHeadings.topic.not.claim',
        'geo.pillar.factDensity.below.target.add',
        'geo.pillar.factDensity.below.target.tighten',
    ];

    /**
     * @param list<string> $sampleParams representative params for fuzzy key match
     */
    public static function urlForNote(string $noteText, ?Entry $entry, array $sampleParams = []): ?string
    {
        foreach (self::SETTINGS_ROUTES as $key => $route) {
            if (self::matchesKey($noteText, $key)) {
                return UrlHelper::cpUrl($route);
            }
        }

        foreach (self::AUTHORS_ROUTES as $key => $route) {
            if (self::matchesKey($noteText, $key)) {
                return UrlHelper::cpUrl($route);
            }
        }

        foreach (self::ENTRY_EDIT_KEYS as $key) {
            if (self::matchesKey($noteText, $key, $sampleParams) && $entry !== null) {
                return $entry->getCpEditUrl();
            }
        }

        return null;
    }

    /**
     * @param array<array-key, scalar> $params
     */
    private static function matchesKey(string $noteText, string $key, array $params = []): bool
    {
        foreach (self::localesToTry() as $locale) {
            $exact = Craft::t('beacon', $key, $params, $locale);
            if ($noteText === $exact) {
                return true;
            }

            $template = Craft::t('beacon', $key, [], $locale);
            if (!str_contains($template, '{')) {
                continue;
            }

            $pattern = preg_quote($template, '/');
            $pattern = (string) preg_replace('/\\\\\{[a-zA-Z0-9_]+\\\\\}/', '.*?', $pattern);
            if (preg_match('/^' . $pattern . '$/u', $noteText)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string|null> */
    private static function localesToTry(): array
    {
        $current = Craft::$app->language ?? null;

        return array_values(array_unique([$current, 'en', 'en-US', null], SORT_REGULAR));
    }
}
