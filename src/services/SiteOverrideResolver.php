<?php

namespace anvildev\beacon\services;

/**
 * @phpstan-type SiteOverrides array<string, array{enabled?: bool, config?: array<string, mixed>}>
 */
final class SiteOverrideResolver
{
    /**
     * Returns the per-site effective config: site-override values shallow-merged
     * onto the global config (override wins per top-level key).
     *
     * NOTE: merge is shallow by design. All current providers (GA4, GTM, Facebook
     * Pixel, Matomo, Custom) use flat configs. If a future provider introduces a
     * nested config array, this method will clobber the nested array entirely
     * rather than deep-merging — switch to ArrayHelper::merge() at that point.
     *
     * @param array<string, mixed> $globalConfig
     * @param SiteOverrides|null $siteOverrides
     * @return array<string, mixed>
     */
    public function resolve(array $globalConfig, ?array $siteOverrides, string $siteUid): array
    {
        return isset($siteOverrides[$siteUid]['config'])
            ? array_merge($globalConfig, $siteOverrides[$siteUid]['config'])
            : $globalConfig;
    }

    /**
     * @param SiteOverrides|null $siteOverrides
     */
    public function isDisabledForSite(?array $siteOverrides, string $siteUid): bool
    {
        return ($siteOverrides[$siteUid]['enabled'] ?? null) === false;
    }
}
