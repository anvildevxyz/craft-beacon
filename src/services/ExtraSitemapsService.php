<?php

namespace anvildev\beacon\services;

use anvildev\beacon\helpers\SeoFieldReader;
use anvildev\beacon\helpers\Xml;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Entry;
use yii\base\Component;

/**
 * Renders the news/image/video XML sitemap variants. Sibling to
 * {@see SitemapService}, which handles the standard `urlset`/index pair.
 *
 * News follows the Google News Sitemap protocol (`news:` namespace) — only
 * entries published in the last 48 hours qualify per Google's spec. Image and
 * video harvest related Asset elements; emit nothing if no related media is
 * found.
 */
class ExtraSitemapsService extends Component
{
    /**
     * Google's spec — only entries published in the last 48 hours appear in news sitemaps.
     */
    public const NEWS_WINDOW_HOURS = 48;

    /**
     * @param list<string> $newsSectionHandles
     */
    public function renderNews(int $siteId, array $newsSectionHandles, string $publicationName, string $language): ?string
    {
        if ($newsSectionHandles === []) {
            return null;
        }

        /** @var list<Entry> $entries */
        $entries = Entry::find()
            ->section($newsSectionHandles)
            ->siteId($siteId)
            ->status(Entry::STATUS_LIVE)
            ->postDate('>= ' . (new \DateTimeImmutable('-' . self::NEWS_WINDOW_HOURS . ' hours'))->format('Y-m-d H:i:s'))
            ->orderBy(['postDate' => SORT_DESC])
            ->limit(1000)
            ->all();

        // Loop-invariants — hoist escaping of publication fields outside the loop.
        $pubName = Xml::escape($publicationName);
        $pubLang = Xml::escape($language);

        $body = '';
        foreach ($entries as $entry) {
            $url = SeoFieldReader::indexableUrl($entry);
            if ($url === null || $entry->postDate === null) {
                continue;
            }
            $pubDate = Xml::escape($entry->postDate->format(\DateTimeInterface::ATOM));
            $title = Xml::escape(SeoFieldReader::headlineFor($entry));
            $body .= "  <url>\n"
                . '    <loc>' . Xml::escape($url) . "</loc>\n"
                . "    <news:news>\n"
                . "      <news:publication>\n"
                . "        <news:name>{$pubName}</news:name>\n"
                . "        <news:language>{$pubLang}</news:language>\n"
                . "      </news:publication>\n"
                . "      <news:publication_date>{$pubDate}</news:publication_date>\n"
                . "      <news:title>{$title}</news:title>\n"
                . "    </news:news>\n"
                . "  </url>\n";
        }

        if ($body === '') {
            return null;
        }

        return Xml::urlsetOpen(['news' => 'http://www.google.com/schemas/sitemap-news/0.9']) . "\n"
            . $body
            . "</urlset>\n";
    }

    /**
     * @param list<string> $sectionHandles
     */
    public function renderImage(int $siteId, array $sectionHandles): ?string
    {
        return $this->renderMedia($siteId, $sectionHandles, kind: 'image', namespace: 'image', tag: 'image');
    }

    /**
     * @param list<string> $sectionHandles
     */
    public function renderVideo(int $siteId, array $sectionHandles): ?string
    {
        return $this->renderMedia($siteId, $sectionHandles, kind: 'video', namespace: 'video', tag: 'video');
    }

    /**
     * @param list<string> $sectionHandles
     */
    private function renderMedia(int $siteId, array $sectionHandles, string $kind, string $namespace, string $tag): ?string
    {
        if ($sectionHandles === []) {
            return null;
        }

        $entries = Entry::find()
            ->section($sectionHandles)
            ->siteId($siteId)
            ->status(Entry::STATUS_LIVE)
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->limit(50000);

        $body = '';

        // Buffer entries in pages of 500 so related assets can be resolved in a
        // single query per page instead of one query per entry (avoids an N+1
        // on cold media-sitemap regeneration of large sites).
        $page = [];
        foreach ($entries->each(500) as $entry) {
            assert($entry instanceof Entry);
            $page[] = $entry;
            if (count($page) >= 500) {
                $body .= $this->renderMediaPage($page, $kind, $siteId);
                $page = [];
            }
        }
        if ($page !== []) {
            $body .= $this->renderMediaPage($page, $kind, $siteId);
        }

        if ($body === '') {
            return null;
        }

        return Xml::urlsetOpen([$namespace => "http://www.google.com/schemas/sitemap-{$tag}/1.1"]) . "\n"
            . $body
            . "</urlset>\n";
    }

    /**
     * Renders the `<url>` blocks for one page of entries, resolving all related
     * media in a single batched query keyed back to its source entry.
     *
     * @param list<Entry> $entries
     */
    private function renderMediaPage(array $entries, string $kind, int $siteId): string
    {
        $entryIds = array_map(static fn(Entry $entry): int => (int) $entry->id, $entries);
        $assetsByEntry = $this->relatedAssetsByEntry($entryIds, $kind, $siteId);

        $body = '';
        foreach ($entries as $entry) {
            $url = SeoFieldReader::indexableUrl($entry);
            if ($url === null) {
                continue;
            }
            $assets = $assetsByEntry[(int) $entry->id] ?? [];
            if ($assets === []) {
                continue;
            }
            $body .= "  <url>\n" . '    <loc>' . Xml::escape($url) . "</loc>\n";
            foreach ($assets as $asset) {
                $assetUrl = $asset->getUrl();
                if ($assetUrl === null) {
                    continue;
                }
                if ($kind === 'image') {
                    $body .= "    <image:image>\n"
                        . '      <image:loc>' . Xml::escape($assetUrl) . "</image:loc>\n";
                    $title = (string) ($asset->title ?? '');
                    if ($title !== '') {
                        $body .= '      <image:title>' . Xml::escape($title) . "</image:title>\n";
                    }
                    $body .= "    </image:image>\n";
                } else {
                    $videoTitle = Xml::escape((string) ($asset->title ?? $entry->title));
                    $thumb = Xml::escape($assetUrl);
                    $body .= "    <video:video>\n"
                        . "      <video:thumbnail_loc>{$thumb}</video:thumbnail_loc>\n"
                        . "      <video:title>{$videoTitle}</video:title>\n"
                        . "      <video:description>{$videoTitle}</video:description>\n"
                        . "      <video:content_loc>{$thumb}</video:content_loc>\n"
                        . "    </video:video>\n";
                }
            }
            $body .= "  </url>\n";
        }

        return $body;
    }

    /**
     * Resolves media assets related to a page of entries in one relations lookup
     * plus one asset query, grouped by source entry id and preserving relation
     * sort order.
     *
     * @param list<int> $entryIds
     * @return array<int, list<Asset>>
     */
    private function relatedAssetsByEntry(array $entryIds, string $kind, int $siteId): array
    {
        if ($entryIds === []) {
            return [];
        }

        /** @var list<array{sourceId: int|string, targetId: int|string}> $relations */
        $relations = (new Query())
            ->select(['sourceId', 'targetId'])
            ->from(Table::RELATIONS)
            ->where(['sourceId' => $entryIds])
            ->andWhere(['or', ['sourceSiteId' => null], ['sourceSiteId' => $siteId]])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();

        if ($relations === []) {
            return [];
        }

        $targetIds = array_values(array_unique(array_map(
            static fn(array $row): int => (int) $row['targetId'],
            $relations,
        )));

        /** @var array<int, Asset> $assetsById */
        $assetsById = [];
        foreach (Asset::find()->id($targetIds)->kind($kind)->siteId($siteId)->all() as $asset) {
            $assetsById[(int) $asset->id] = $asset;
        }

        return self::groupAssetsBySource($relations, $assetsById);
    }

    /**
     * Groups loaded assets under their source entry id, preserving the order of
     * `$relations` (relation sort order) and dropping any relation whose target
     * was filtered out of `$assetsById` (wrong kind, missing, or not on the
     * site). Pure so the grouping can be unit tested without a database.
     *
     * @param list<array{sourceId: int|string, targetId: int|string}> $relations
     * @param array<int, Asset> $assetsById
     * @return array<int, list<Asset>>
     */
    public static function groupAssetsBySource(array $relations, array $assetsById): array
    {
        $byEntry = [];
        foreach ($relations as $row) {
            $targetId = (int) $row['targetId'];
            if (isset($assetsById[$targetId])) {
                $byEntry[(int) $row['sourceId']][] = $assetsById[$targetId];
            }
        }

        return $byEntry;
    }
}
