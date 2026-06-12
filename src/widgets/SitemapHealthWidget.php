<?php

namespace anvildev\beacon\widgets;

use anvildev\beacon\enums\RenderCacheType;
use Craft;
use craft\base\Widget;

/**
 * @phpstan-type SitemapHealthChunkRow array{key: string, urls: int, bytes: int, generatedAt: ?string}
 * @phpstan-type SitemapHealthWidgetData array{
 *     hasCache: bool,
 *     mode: string,
 *     parts: int,
 *     totalUrls: int,
 *     lastRegen: ?string,
 *     chunks: list<SitemapHealthChunkRow>,
 * }
 */
final class SitemapHealthWidget extends Widget
{
    use DefaultsToTwoColumnsTrait;
    use RegistersBeaconCpAssetTrait;

    public static function displayName(): string
    {
        return Craft::t('beacon', 'widgets.sitemapHealth.sitemap.health');
    }

    public static function icon(): ?string
    {
        return 'sitemap';
    }

    public function getTitle(): ?string
    {
        return Craft::t('beacon', 'widgets.sitemapHealth.sitemap.health');
    }

    public function getBodyHtml(): ?string
    {
        $this->registerBeaconCpAsset();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        return Craft::$app->getView()->renderTemplate('beacon/_widgets/sitemap-health', [
            'data' => Craft::$app->getCache()->getOrSet(
                "beacon.sitemapHealth:$siteId",
                fn() => $this->loadData($siteId),
                60,
            ),
        ]);
    }

    /** @return SitemapHealthWidgetData */
    private function loadData(int $siteId): array
    {
        $rows = Craft::$app->getDb()->createCommand(
            'SELECT [[contentKey]], [[generatedAt]], [[content]]
             FROM {{%beacon_render_cache}}
             WHERE [[siteId]] = :siteId AND [[type]] = :type
             ORDER BY [[contentKey]] ASC',
            ['siteId' => $siteId, 'type' => RenderCacheType::Sitemap->value],
        )->queryAll();

        $chunks = [];
        $totalUrls = 0;
        $lastRegen = null;
        $hasIndex = false;
        $partCount = 0;
        foreach ($rows as $row) {
            $key = $row['contentKey'] === null ? '(single)' : (string) $row['contentKey'];
            $content = (string) $row['content'];
            $urls = substr_count($content, '<url>');
            // Index row aggregates child URL counts in its `<sitemap>` entries
            // (not `<url>`), so its `substr_count` is 0 — but be defensive and
            // exclude it from totals regardless.
            if ($key === 'index') {
                $hasIndex = true;
            } else {
                $totalUrls += $urls;
                if (str_starts_with($key, 'p:')) {
                    $partCount++;
                }
            }
            $generatedAt = $row['generatedAt'] !== null ? (string) $row['generatedAt'] : null;
            if ($generatedAt !== null && ($lastRegen === null || $generatedAt > $lastRegen)) {
                $lastRegen = $generatedAt;
            }
            $chunks[] = [
                'key' => $key,
                'urls' => $urls,
                'bytes' => strlen($content),
                'generatedAt' => $generatedAt,
            ];
        }

        return [
            'hasCache' => $chunks !== [],
            'mode' => match (true) {
                $hasIndex => 'chunked',
                $chunks === [] => 'empty',
                default => 'single',
            },
            'parts' => $partCount,
            'totalUrls' => max(0, $totalUrls),
            'lastRegen' => $lastRegen,
            'chunks' => $chunks,
        ];
    }
}
