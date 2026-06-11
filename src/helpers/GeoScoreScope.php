<?php

namespace anvildev\beacon\helpers;

use craft\elements\Entry;

/**
 * GEO score eligibility predicates shared by the SEO field chip and the
 * post-save recompute listener.
 */
final class GeoScoreScope
{
    /**
     * Whether a section handle is in the configured allowlist.
     * Empty allowlist means all sections qualify.
     *
     * @param list<string> $allowlist
     */
    public static function sectionInScope(?string $sectionHandle, array $allowlist): bool
    {
        if ($sectionHandle === null || trim($sectionHandle) === '') {
            return false;
        }
        return $allowlist === [] || in_array($sectionHandle, $allowlist, true);
    }

    /**
     * Whether the CP score chip should render for this entry.
     *
     * @param list<string> $sectionAllowlist
     */
    public static function entryEligibleForChip(?Entry $entry, bool $geoScoreEnabled, array $sectionAllowlist): bool
    {
        if ($entry === null || !$entry->id || !$entry->siteId || !$geoScoreEnabled) {
            return false;
        }
        return self::sectionInScope($entry->getSection()?->handle, $sectionAllowlist);
    }
}
