<?php

namespace anvildev\beacon\services\links;

use anvildev\beacon\Plugin;
use anvildev\beacon\records\LinkIndexRecord;
use anvildev\beacon\records\LinkRecord;
use anvildev\beacon\records\LinkSuggestionRecord;
use Craft;
use craft\base\Component;
use craft\elements\Entry;
use yii\caching\TagDependency;
use yii\db\Expression;

class ReportService extends Component
{
    /** @param int[]|float[] $values */
    public function calculateAverage(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        return array_sum($values) / count($values);
    }

    public function calculateAcceptanceRate(int $accepted, int $dismissed): float
    {
        $total = $accepted + $dismissed;
        if ($total === 0) {
            return 0.0;
        }
        return $accepted / $total;
    }

    /** @return array{elementId: int, title: string, sectionName: string, outboundCount: int, dateCreated: string} */
    public function formatOrphanRow(int $elementId, string $title, string $sectionName, int $outboundCount, string $dateCreated): array
    {
        return [
            'elementId' => $elementId,
            'title' => $title,
            'sectionName' => $sectionName,
            'outboundCount' => $outboundCount,
            'dateCreated' => $dateCreated,
        ];
    }

    /** @return array{totalIndexed: int, totalEntries: int, averageLinks: float, orphanCount: int, noOutboundCount: int, acceptanceRate: float} */
    public function getOverviewStats(int $siteId, int $cacheDuration = 3600): array
    {
        $cache = Craft::$app->getCache();
        $key = "beacon_report_overview_{$siteId}";
        $dependency = new TagDependency(['tags' => ['beacon_reports']]);
        return $cache->getOrSet($key, function() use ($siteId) {
            $links = Plugin::getInstance()?->links;
            if ($links === null) {
                return [
                    'totalIndexed' => 0,
                    'totalEntries' => 0,
                    'averageLinks' => 0.0,
                    'orphanCount' => 0,
                    'noOutboundCount' => 0,
                    'acceptanceRate' => 0.0,
                ];
            }
            $totalIndexed = $links->index->countIndexed($siteId);
            // Only count entries that belong to a section (exclude nested/matrix entries)
            $sectionEntryIds = Entry::find()->siteId($siteId)->status(null)->ids();
            $sectionEntryIdSet = array_fill_keys($sectionEntryIds, true);

            $totalEntries = count($sectionEntryIds);
            $outboundCounts = LinkRecord::find()
                ->select([new Expression('COUNT(*) as cnt')])
                ->where(['sourceSiteId' => $siteId])
                ->groupBy('sourceElementId')
                ->column();
            $averageLinks = $this->calculateAverage($outboundCounts);
            $allIndexedIds = LinkIndexRecord::find()->where(['siteId' => $siteId])->select('elementId')->column();
            // Filter to only section entries
            $allIndexedIds = array_filter($allIndexedIds, fn($id) => isset($sectionEntryIdSet[$id]));
            $hasInboundIds = LinkRecord::find()->where(['targetSiteId' => $siteId])->select('targetElementId')->distinct()->column();
            $orphanCount = count(array_diff($allIndexedIds, $hasInboundIds));
            $hasOutboundIds = LinkRecord::find()->where(['sourceSiteId' => $siteId])->select('sourceElementId')->distinct()->column();
            $noOutboundCount = count(array_diff($allIndexedIds, $hasOutboundIds));
            $accepted = (int) LinkSuggestionRecord::find()->where(['siteId' => $siteId, 'status' => 'accepted'])->count();
            $dismissed = (int) LinkSuggestionRecord::find()->where(['siteId' => $siteId, 'status' => 'dismissed'])->count();
            return [
                'totalIndexed' => $totalIndexed,
                'totalEntries' => (int) $totalEntries,
                'averageLinks' => $averageLinks,
                'orphanCount' => $orphanCount,
                'noOutboundCount' => $noOutboundCount,
                'acceptanceRate' => $this->calculateAcceptanceRate($accepted, $dismissed),
            ];
        }, $cacheDuration, $dependency);
    }

    /** @return array<int, array{elementId: int, title: string, sectionName: string, outboundCount: int, dateCreated: string}> */
    public function getOrphanPages(int $siteId, ?string $sectionHandle = null): array
    {
        $allIndexedIds = LinkIndexRecord::find()->where(['siteId' => $siteId])->select('elementId')->column();
        $hasInboundIds = LinkRecord::find()->where(['targetSiteId' => $siteId])->select('targetElementId')->distinct()->column();
        $orphanIds = array_diff($allIndexedIds, $hasInboundIds);
        if ($orphanIds === []) {
            return [];
        }
        $query = Entry::find()->siteId($siteId)->id($orphanIds)->status(null);
        if ($sectionHandle !== null) {
            $query->section($sectionHandle);
        }
        $entries = $query->all();
        if ($entries === []) {
            return [];
        }

        // Batch-fetch outbound counts to avoid N+1 queries
        $entryIds = array_map(fn($e) => $e->id, $entries);
        $outboundCounts = LinkRecord::find()
            ->select(['sourceElementId', new Expression('COUNT(*) as cnt')])
            ->where(['sourceElementId' => $entryIds, 'sourceSiteId' => $siteId])
            ->groupBy('sourceElementId')
            ->asArray()
            ->all();
        $outboundMap = [];
        foreach ($outboundCounts as $row) {
            $outboundMap[(int) $row['sourceElementId']] = (int) $row['cnt'];
        }

        $results = [];
        foreach ($entries as $entry) {
            $section = $entry->getSection();
            if ($section === null) {
                continue;
            }
            $results[] = $this->formatOrphanRow(
                elementId: $entry->id,
                title: $entry->title ?? '(untitled)',
                sectionName: $section->name,
                outboundCount: $outboundMap[$entry->id] ?? 0,
                dateCreated: $entry->dateCreated?->format('Y-m-d') ?? '',
            );
        }
        return $results;
    }

    /** @return array<int, array{elementId: int, title: string, sectionName: string, inboundCount: int, outboundCount: int}> */
    public function getLinkMap(int $siteId, ?string $sectionHandle = null): array
    {
        $query = Entry::find()->siteId($siteId)->status(null);
        if ($sectionHandle !== null) {
            $query->section($sectionHandle);
        }
        $entries = $query->all();
        if ($entries === []) {
            return [];
        }

        $entryIds = array_map(fn($e) => $e->id, $entries);

        // Batch-fetch outbound counts grouped by source to avoid 2×N queries.
        $outboundRows = LinkRecord::find()
            ->select(['sourceElementId', new Expression('COUNT(*) as cnt')])
            ->where(['sourceElementId' => $entryIds, 'sourceSiteId' => $siteId])
            ->groupBy('sourceElementId')
            ->asArray()
            ->all();
        $outboundMap = [];
        foreach ($outboundRows as $row) {
            $outboundMap[(int) $row['sourceElementId']] = (int) $row['cnt'];
        }

        // Batch-fetch inbound counts grouped by target.
        $inboundRows = LinkRecord::find()
            ->select(['targetElementId', new Expression('COUNT(*) as cnt')])
            ->where(['targetElementId' => $entryIds, 'targetSiteId' => $siteId, 'isExternal' => false])
            ->groupBy('targetElementId')
            ->asArray()
            ->all();
        $inboundMap = [];
        foreach ($inboundRows as $row) {
            $inboundMap[(int) $row['targetElementId']] = (int) $row['cnt'];
        }

        $results = [];
        foreach ($entries as $entry) {
            $section = $entry->getSection();
            if ($section === null) {
                continue;
            }
            $results[] = [
                'elementId' => (int) $entry->id,
                'title' => $entry->title ?? '(untitled)',
                'sectionName' => (string) $section->name,
                'inboundCount' => $inboundMap[$entry->id] ?? 0,
                'outboundCount' => $outboundMap[$entry->id] ?? 0,
            ];
        }
        return $results;
    }

    public function invalidateCache(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), 'beacon_reports');
    }
}
