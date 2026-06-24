<?php

namespace anvildev\beacon\services;

use anvildev\beacon\helpers\AiUsagePolicy;
use Craft;
use yii\base\Component;

/**
 * Resolves the effective AI-usage policy for an entry (entry → section →
 * global) and renders the per-site surfaces that aren't already carried on the
 * per-page meta tags: the robots.txt Content-Signal block and the TDMRep
 * `/.well-known/tdmrep.json` manifest.
 *
 * Per-page surfaces (robots `noai` meta, X-Robots-Tag, Content-Usage header)
 * live in MetaResolverService / BeaconVariable; per-site surfaces live here.
 * All token mappings come from {@see AiUsagePolicy} so the two stay in lockstep.
 *
 * @phpstan-type SectionPolicies array<string,string>
 * @phpstan-type SectionPrefixes array<string,string>
 * @phpstan-type TdmRepEntry array{location:string, 'tdm-reservation':int, 'tdm-policy'?:string}
 */
class AiUsageService extends Component
{
    /**
     * Effective policy with entry beating section beating global. Each level
     * may defer (blank / `inherit`); the global default is the floor and
     * coerces to `allow`.
     */
    public function resolvePolicy(?string $entryPolicy, ?string $sectionPolicy, ?string $globalPolicy): string
    {
        return AiUsagePolicy::normalizeOrInherit($entryPolicy)
            ?? AiUsagePolicy::normalizeOrInherit($sectionPolicy)
            ?? AiUsagePolicy::normalize($globalPolicy);
    }

    /**
     * True when anything on the site reserves rights — used to decide whether
     * to serve the TDMRep manifest / Content-Signal block at all (a fully
     * `allow` site emits nothing new).
     *
     * @param SectionPolicies $sectionPolicies
     */
    public function hasAnyRestrictive(?string $globalPolicy, array $sectionPolicies): bool
    {
        if (AiUsagePolicy::isRestrictive(AiUsagePolicy::normalize($globalPolicy))) {
            return true;
        }
        foreach ($sectionPolicies as $policy) {
            if (AiUsagePolicy::isRestrictive(AiUsagePolicy::normalize($policy))) {
                return true;
            }
        }
        return false;
    }

    /**
     * robots.txt lines for the Content Signals Policy. The directive itself is
     * site-wide (the spec's `Content-Signal` is not reliably path-scoped), so
     * it reflects the global default; per-section reservations are surfaced
     * losslessly via the TDMRep manifest (which is location-scoped) and the
     * per-page meta/headers. Section policies are echoed as comments for
     * operator visibility. Returns [] when nothing is restrictive.
     *
     * @param SectionPolicies $sectionPolicies handle => policy
     * @param SectionPrefixes $sectionPrefixes handle => static URL prefix
     * @return list<string>
     */
    public function contentSignalLines(?string $globalPolicy, array $sectionPolicies = [], array $sectionPrefixes = []): array
    {
        if (!$this->hasAnyRestrictive($globalPolicy, $sectionPolicies)) {
            return [];
        }

        $lines = ['# AI content-usage signals (Content Signals Policy)'];

        $globalTokens = AiUsagePolicy::contentSignalTokens(AiUsagePolicy::normalize($globalPolicy));
        if ($globalTokens !== []) {
            $lines[] = 'User-agent: *';
            $lines[] = 'Content-Signal: ' . implode(', ', $globalTokens);
        }

        foreach ($sectionPolicies as $handle => $policy) {
            $tokens = AiUsagePolicy::contentSignalTokens(AiUsagePolicy::normalize($policy));
            if ($tokens === []) {
                continue;
            }
            $prefix = $sectionPrefixes[$handle] ?? null;
            $scope = ($prefix !== null && $prefix !== '') ? $prefix : ('section: ' . $handle);
            $lines[] = sprintf('# %s → %s', $scope, implode(', ', $tokens));
        }

        return $lines;
    }

    /**
     * TDMRep manifest entries. Location-scoped, so per-section reservations are
     * represented exactly: `/` for the global default plus one entry per
     * restrictive section that has a derivable static URL prefix. Returns []
     * when nothing reserves rights.
     *
     * @param SectionPolicies $sectionPolicies handle => policy
     * @param SectionPrefixes $sectionPrefixes handle => static URL prefix
     * @return list<TdmRepEntry>
     */
    public function tdmRepManifest(?string $globalPolicy, array $sectionPolicies = [], array $sectionPrefixes = [], ?string $policyUrl = null): array
    {
        $entries = [];

        $global = AiUsagePolicy::normalize($globalPolicy);
        if (AiUsagePolicy::isRestrictive($global)) {
            $entries[] = $this->tdmEntry('/', $global, $policyUrl);
        }

        foreach ($sectionPolicies as $handle => $rawPolicy) {
            $policy = AiUsagePolicy::normalize($rawPolicy);
            if (!AiUsagePolicy::isRestrictive($policy)) {
                continue;
            }
            $prefix = $sectionPrefixes[$handle] ?? null;
            if (!is_string($prefix) || $prefix === '' || $prefix === '/') {
                continue;
            }
            $entries[] = $this->tdmEntry($prefix, $policy, $policyUrl);
        }

        return $entries;
    }

    /**
     * Resolves per-section policies + scopable URL prefixes for a site from the
     * section SEO defaults, for feeding the robots.txt / manifest builders.
     * Only sections with an explicit (non-inherit) restrictive policy are
     * returned. Craft-aware; returns empty maps when Craft is unavailable.
     *
     * @param array<string,array<string,string>> $sectionSeoDefaults
     * @return array{policies: SectionPolicies, prefixes: SectionPrefixes}
     */
    public function gatherSectionScopes(int $siteId, array $sectionSeoDefaults): array
    {
        $policies = [];
        $prefixes = [];

        if (!class_exists(Craft::class) || Craft::$app === null) {
            return ['policies' => $policies, 'prefixes' => $prefixes];
        }

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $handle = $section->handle;
            $raw = $sectionSeoDefaults[$handle]['aiUsage'] ?? null;
            $policy = AiUsagePolicy::normalizeOrInherit(is_string($raw) ? $raw : null);
            if ($policy === null || !AiUsagePolicy::isRestrictive($policy)) {
                continue;
            }
            $policies[$handle] = $policy;

            $siteSettings = $section->getSiteSettings()[$siteId] ?? null;
            $prefix = AiUsagePolicy::staticPrefixFromUriFormat($siteSettings?->uriFormat);
            if ($prefix !== null) {
                $prefixes[$handle] = $prefix;
            }
        }

        return ['policies' => $policies, 'prefixes' => $prefixes];
    }

    /**
     * @return TdmRepEntry
     */
    private function tdmEntry(string $location, string $policy, ?string $policyUrl): array
    {
        $entry = [
            'location' => $location,
            'tdm-reservation' => AiUsagePolicy::tdmReservation($policy),
        ];
        if (is_string($policyUrl) && trim($policyUrl) !== '') {
            $entry['tdm-policy'] = trim($policyUrl);
        }
        return $entry;
    }
}
