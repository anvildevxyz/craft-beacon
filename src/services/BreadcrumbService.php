<?php

namespace anvildev\beacon\services;

use anvildev\beacon\models\BreadcrumbSettings;
use craft\base\ElementInterface;
use craft\elements\Entry;
use yii\base\Component;

/**
 * @phpstan-import-type BreadcrumbItem from \anvildev\beacon\types\ArrayShapes
 * @phpstan-import-type BreadcrumbItemInput from \anvildev\beacon\types\ArrayShapes
 */
class BreadcrumbService extends Component
{
    /**
     * Per-request memo keyed by "siteId:entryId" (entryId is 0 for null-entry).
     * GraphQL queries that return many entries call {@see self::getResolved()}
     * once per entry; without an entry-aware key, every entry inherits the
     * first entry's breadcrumb chain.
     *
     * @var array<string, array<int, BreadcrumbItem>>
     */
    private array $cache = [];
    /** @var array<int, array<string, mixed>>|null */
    private ?array $override = null;

    /**
     * Heuristic: strip the dynamic-segment portion of a uriFormat to derive
     * a section index URL path. Returns '' when the format is purely dynamic
     * (e.g. '{slug}'), in which case the caller should skip the section item.
     */
    public static function deriveSectionPath(string $uriFormat): string
    {
        $bracePos = strpos($uriFormat, '{');
        $prefix = trim($bracePos === false ? $uriFormat : substr($uriFormat, 0, $bracePos), '/');
        return $prefix === '' ? '' : '/' . $prefix;
    }

    /**
     * @param array<int, BreadcrumbItemInput>|null $override
     * @return array<int, BreadcrumbItem>
     */
    public function resolve(
        ?Entry $entry,
        BreadcrumbSettings $settings,
        string $siteBaseUrl,
        ?array $override = null,
    ): array {
        if ($override !== null) {
            return $this->normalizeOverride($override);
        }

        if (!$settings->enabled) {
            return [];
        }

        $items = [['name' => $settings->homeLabel, 'url' => $siteBaseUrl]];

        if ($entry === null) {
            return $items;
        }

        return [
            ...$items,
            ...$this->resolveAncestorsAndSection($entry),
            ['name' => (string) ($entry->title ?? '')],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $override
     * @return array<int, BreadcrumbItem>
     */
    private function normalizeOverride(array $override): array
    {
        $normalized = [];
        foreach ($override as $item) {
            if (!is_array($item) || !isset($item['name']) || !is_string($item['name'])) {
                if (\Craft::$app !== null) {
                    \Craft::warning('Skipped invalid breadcrumb override item.', 'beacon');
                }
                continue;
            }
            $entry = ['name' => $item['name']];
            if (isset($item['url']) && is_string($item['url'])) {
                $entry['url'] = $item['url'];
            }
            $normalized[] = $entry;
        }
        return $normalized;
    }

    /**
     * @return array<int, BreadcrumbItem>
     */
    private function resolveAncestorsAndSection(Entry $entry): array
    {
        /** @var list<ElementInterface> $ancestors */
        $ancestors = $entry->getAncestors()->all();
        if ($ancestors !== []) {
            return array_map(
                fn(ElementInterface $a): array => array_filter([
                    'name' => (string) ($a->title ?? ''),
                    'url' => is_string($a->url ?? null) ? $a->url : null,
                ], static fn($v) => $v !== null),
                $ancestors,
            );
        }

        $section = $this->extractSection($entry);
        if ($section !== null) {
            $sectionPath = self::deriveSectionPath($section['uriFormat']);
            if ($sectionPath !== '') {
                return [['name' => $section['name'], 'url' => $sectionPath]];
            }
        }

        return [];
    }

    /**
     * @param array<int, BreadcrumbItem> $items
     * @return array<string, mixed>|null  null if items is empty
     */
    public function asJsonLd(array $items): ?array
    {
        if ($items === []) {
            return null;
        }

        $listItems = array_map(function(array $item, int $position): array {
            $listItem = [
                '@type' => 'ListItem',
                'position' => $position + 1,
                'name' => $item['name'],
            ];
            if (isset($item['url'])) {
                $listItem['item'] = $item['url'];
            }
            return $listItem;
        }, $items, array_keys($items));

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $listItems,
        ];
    }

    /**
     * @return array{name: string, uriFormat: string}|null null for singles/drafts/no-section
     */
    private function extractSection(Entry $entry): ?array
    {
        $section = $entry->getSection();
        if ($section === null || $section->type === 'single') {
            return null;
        }
        $siteId = (int) ($entry->siteId ?? 0);
        $siteSettings = $section->getSiteSettings()[$siteId] ?? null;
        if ($siteSettings === null) {
            return null;
        }
        return [
            'name' => (string) $section->name,
            'uriFormat' => (string) $siteSettings->uriFormat,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function setOverride(array $items): void
    {
        $this->override = $items;
        $this->cache = [];
    }

    /**
     * @return array<int, BreadcrumbItem>
     */
    public function getResolved(?Entry $entry, BreadcrumbSettings $settings, string $siteBaseUrl): array
    {
        $entryId = $entry?->id !== null ? (int) $entry->id : 0;
        $key = ($settings->siteId ?? 0) . ':' . $entryId;
        return $this->cache[$key] ??= $this->resolve($entry, $settings, $siteBaseUrl, $this->override);
    }
}
