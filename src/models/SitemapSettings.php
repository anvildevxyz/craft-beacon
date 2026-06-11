<?php

namespace anvildev\beacon\models;

/**
 * @phpstan-type SectionSitemapOverride array{priority?: float, changefreq?: string}
 */
class SitemapSettings
{
    /** @var list<string> */
    public const CHANGEFREQ_VALUES = [
        'always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never',
    ];

    /**
     * @param list<string> $sections
     * @param list<string> $excludeSections
     * @param list<string> $newsSections
     * @param array<string, SectionSitemapOverride> $sectionSitemap Overrides keyed by section handle. Producers ({@see \anvildev\beacon\controllers\SitemapSettingsController::normalizeSectionSitemapOverrides()} and {@see \anvildev\beacon\services\SiteSettingsService::decodeSectionSitemap()}) normalize values into this exact shape before construction.
     * @param array<string, array<string,scalar|null>> $geoMarkdownFrontMatter Per-section GEO Markdown front matter overrides (keyed by section handle).
     */
    public function __construct(
        public readonly int $siteId,
        public readonly array $sections = [],
        public readonly array $excludeSections = [],
        public readonly float $priority = 0.8,
        public readonly string $changefreq = 'weekly',
        public readonly array $newsSections = [],
        public readonly array $sectionSitemap = [],
        public readonly array $geoMarkdownFrontMatter = [],
    ) {
    }

    /**
     * Section handles included in the sitemap: configured sections minus exclusions.
     *
     * @return list<string>
     */
    public function includedSectionHandles(): array
    {
        return array_values(array_diff($this->sections, $this->excludeSections));
    }

    /**
     * Resolved values for URLs belonging to `$sectionHandle` (falls back to site defaults).
     *
     * @return array{priority: float, changefreq: string}
     */
    public function resolveForSection(string $sectionHandle): array
    {
        $o = $this->sectionSitemap[$sectionHandle] ?? null;
        $priority = max(0.0, min(1.0, $o['priority'] ?? $this->priority));
        $changefreq = ($o['changefreq'] ?? '') !== '' ? $o['changefreq'] : $this->changefreq;

        return ['priority' => $priority, 'changefreq' => $changefreq];
    }

    public static function isValidChangefreq(string $value): bool
    {
        return in_array($value, self::CHANGEFREQ_VALUES, true);
    }
}
