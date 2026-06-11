<?php

namespace anvildev\beacon\services;

use anvildev\beacon\helpers\Xml;
use yii\base\Component;

/**
 * @phpstan-type SitemapCoreRow array{url: string, lastmod: string, priority?: float|int|string, changefreq?: string}
 * @phpstan-type SitemapRow array{url: string, lastmod: string, priority: float, changefreq: string}
 * @phpstan-type SitemapExtra array{loc: string, lastmod?: ?string, changefreq?: ?string, priority?: ?float}
 */
class SitemapService extends Component
{
    public const SITEMAP_INDEX_THRESHOLD = 50000;

    public const MAX_URLS_PER_FILE = 50000;

    /**
     * Clamp max URLs per sitemap chunk (sitemaps.org limit).
     */
    public function effectiveMaxUrlsPerFile(?int $fromConfig): int
    {
        if ($fromConfig === null || $fromConfig <= 0) {
            return self::SITEMAP_INDEX_THRESHOLD;
        }

        return min(self::MAX_URLS_PER_FILE, max(1, $fromConfig));
    }

    /**
     * Merge core rows with event extras.
     *
     * **Duplicate URLs:** extras overwrite core; among extras later entries overwrite earlier ones.
     *
     * @param list<SitemapCoreRow> $coreRows
     * @param list<SitemapExtra> $extras
     * @return list<SitemapRow>
     */
    public function mergeCoreAndExtras(
        array $coreRows,
        array $extras,
        float $defaultPriority,
        string $defaultChangefreq,
    ): array {
        /** @var array<string, SitemapRow> $map */
        $map = [];

        foreach ($coreRows as $row) {
            $url = $row['url'];
            $map[$url] = [
                'url' => $url,
                'lastmod' => $row['lastmod'],
                'priority' => (isset($row['priority']) && is_numeric($row['priority']))
                    ? max(0.0, min(1.0, (float) $row['priority']))
                    : $defaultPriority,
                'changefreq' => (isset($row['changefreq']) && $row['changefreq'] !== '')
                    ? $row['changefreq']
                    : $defaultChangefreq,
            ];
        }

        foreach ($extras as $extra) {
            $loc = $extra['loc'];
            if (!is_string($loc) || $loc === '') {
                continue;
            }
            $extraLastmod = (isset($extra['lastmod']) && $extra['lastmod'] !== '') ? $extra['lastmod'] : null;
            $base = $map[$loc] ?? [
                'url' => $loc,
                'lastmod' => $extraLastmod ?? gmdate('c'),
                'priority' => $defaultPriority,
                'changefreq' => $defaultChangefreq,
            ];
            $map[$loc] = [
                'url' => $loc,
                'lastmod' => $extraLastmod ?? $base['lastmod'],
                'priority' => isset($extra['priority']) ? (float) $extra['priority'] : $base['priority'],
                'changefreq' => (isset($extra['changefreq']) && $extra['changefreq'] !== '')
                    ? $extra['changefreq']
                    : $base['changefreq'],
            ];
        }

        $merged = array_values($map);
        usort($merged, fn($a, $b) => strcmp($a['url'], $b['url']));
        return $merged;
    }

    /**
     * @param list<SitemapRow> $rows
     * @return list<list<SitemapRow>>
     */
    public function chunkRows(array $rows, int $maxPerChunk): array
    {
        if ($maxPerChunk < 1) {
            $maxPerChunk = 1;
        }
        if ($rows === []) {
            return [];
        }
        return array_chunk($rows, $maxPerChunk);
    }

    /**
     * @param list<SitemapRow> $chunk
     */
    public function newestLastmodInChunk(array $chunk): string
    {
        $bestTs = 0;
        $bestStr = gmdate('c');
        foreach ($chunk as $r) {
            $lm = $r['lastmod'];
            if (!is_string($lm) || $lm === '') {
                continue;
            }
            $ts = strtotime($lm) ?: 0;
            if ($ts >= $bestTs) {
                $bestTs = $ts;
                $bestStr = $lm;
            }
        }
        return $bestStr;
    }

    /**
     * Rows may omit per-URL priority/changefreq; defaults apply.
     *
     * @param list<array<string, mixed>> $rows Entries with keys `url`, `lastmod`, optional `priority`, `changefreq`
     */
    public function renderUrlset(array $rows, float $defaultPriority = 0.5, string $defaultChangefreq = 'weekly'): string
    {
        $lines = [Xml::urlsetOpen()];

        // Loop-invariants — material at 50k URLs.
        $defaultPriorityStr = number_format($defaultPriority, 1, '.', '');
        $defaultChangefreqStr = Xml::escape($defaultChangefreq);

        foreach ($rows as $row) {
            $loc = Xml::escape((string) ($row['url'] ?? ''));
            $lastmod = Xml::escape((string) ($row['lastmod'] ?? ''));
            $priority = isset($row['priority']) && is_numeric($row['priority'])
                ? number_format((float) $row['priority'], 1, '.', '')
                : $defaultPriorityStr;
            $changefreq = isset($row['changefreq']) && is_string($row['changefreq']) && $row['changefreq'] !== ''
                ? Xml::escape($row['changefreq'])
                : $defaultChangefreqStr;

            $lines[] = '  <url>';
            $lines[] = '    <loc>' . $loc . '</loc>';
            $lines[] = '    <lastmod>' . $lastmod . '</lastmod>';
            $lines[] = '    <changefreq>' . $changefreq . '</changefreq>';
            $lines[] = '    <priority>' . $priority . '</priority>';
            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';
        return implode("\n", $lines);
    }

    /**
     * @param list<array{url:string, lastmod:string}> $entries
     */
    public function renderIndex(array $entries): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];
        foreach ($entries as $entry) {
            $lines[] = '  <sitemap>';
            $lines[] = '    <loc>' . Xml::escape($entry['url']) . '</loc>';
            $lines[] = '    <lastmod>' . Xml::escape($entry['lastmod']) . '</lastmod>';
            $lines[] = '  </sitemap>';
        }
        $lines[] = '</sitemapindex>';
        return implode("\n", $lines);
    }
}
