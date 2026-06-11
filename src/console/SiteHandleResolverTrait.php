<?php

namespace anvildev\beacon\console;

use Craft;
use craft\models\Site;

/**
 * Resolves the `--site=<handle>` console option for the commands that share it.
 *
 * Each resolver applies a different "no handle" default (all sites / primary /
 * none), but they share one handle lookup: an unmatched handle emits a stderr
 * line and counts as a resolution failure.
 *
 * Requires the consuming controller to expose:
 *   - public ?string $site
 *   - public stderr() (provided by yii\console\Controller)
 */
trait SiteHandleResolverTrait
{
    /**
     * `--site` → site id, or null when no handle was passed (caller defaults
     * to all sites). A bad handle also yields null, after a stderr line.
     */
    private function resolveSiteId(): ?int
    {
        return $this->site === null ? null : $this->lookupSiteHandle($this->site)?->id;
    }

    /**
     * `--site` → site id, defaulting to the primary site when no handle was
     * passed. An unknown handle yields null (after a stderr line) — callers
     * must bail with `ExitCode::CONFIG` rather than silently running against
     * a site the operator didn't ask for.
     */
    private function resolveSiteIdOrPrimary(): ?int
    {
        if ($this->site === null) {
            return Craft::$app->getSites()->getPrimarySite()->id;
        }
        return $this->lookupSiteHandle($this->site)?->id;
    }

    /**
     * True when `--site` was passed but matches no site (stderr line already
     * emitted). For commands whose no-handle default is "all sites": bail
     * with `ExitCode::CONFIG` instead of falling through to every site.
     */
    private function unknownSiteHandle(): bool
    {
        return $this->site !== null && $this->lookupSiteHandle($this->site) === null;
    }

    /**
     * `--site` → Site, defaulting to the primary site when no handle was
     * passed; null (after a stderr line) on a bad handle.
     */
    private function resolveSiteOrPrimary(): ?Site
    {
        return $this->site === null
            ? Craft::$app->getSites()->getPrimarySite()
            : $this->lookupSiteHandle($this->site);
    }

    /**
     * `--site` → one-site list, defaulting to every site when no handle was
     * passed; empty list (after a stderr line) on a bad handle.
     *
     * @return list<Site>
     */
    private function resolveSitesOrAll(): array
    {
        if (($this->site ?? '') === '') {
            return array_values(Craft::$app->getSites()->getAllSites());
        }
        $site = $this->lookupSiteHandle($this->site);
        return $site === null ? [] : [$site];
    }

    /**
     * Look up a `--site` handle, emitting a stderr line and returning null when
     * it doesn't match a site.
     */
    private function lookupSiteHandle(string $handle): ?Site
    {
        $site = Craft::$app->getSites()->getSiteByHandle($handle);
        if ($site === null) {
            $this->stderr("Site '$handle' not found.\n");
        }
        return $site;
    }
}
