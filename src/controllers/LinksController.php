<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\Plugin;
use anvildev\beacon\records\LinkRecord;
use anvildev\beacon\records\LinkSuggestionRecord;
use anvildev\beacon\web\assets\links\LinksCpAsset;
use Craft;
use craft\elements\Entry;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP screens for the Links (internal-link-graph) feature: the overview
 * dashboard plus the orphan / link-map / suggestions / click-depth /
 * broken-links / anchor-text / external-links reports, each with CSV export.
 *
 * Merges Whisper's `cp/DashboardController` and `cp/ReportsController` into a
 * single controller. All actions render `beacon/links/<name>` templates and are
 * gated behind {@see BeaconPermissions::VIEW_LINKS} via
 * {@see BeaconCpPermissionTrait}.
 *
 * @author Anvil
 * @since 1.0.0
 */
class LinksController extends \craft\web\Controller
{
    // =========================================================================
    // Traits
    // =========================================================================

    use BeaconCpPermissionTrait;
    use SiteScopedCpControllerTrait;

    // =========================================================================
    // Const Properties
    // =========================================================================

    protected const BEACON_PERMISSION = BeaconPermissions::VIEW_LINKS;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Renders the Links overview dashboard (KPIs, trend sparklines, quick
     * actions).
     */
    public function actionIndex(): Response
    {
        $this->getView()->registerAssetBundle(LinksCpAsset::class);
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $settings = Plugin::$plugin->links->getSettings();
        $stats = Plugin::$plugin->links->reports->getOverviewStats($siteId, $settings->reportCacheDuration);
        $trends = Plugin::$plugin->links->trends->getRecentSnapshots($siteId, 30);
        $brokenCount = count(Plugin::$plugin->links->brokenLinks->findBroken($siteId));

        return $this->renderTemplate('beacon/links/index', [
            'stats' => $stats,
            'trends' => $trends,
            'brokenCount' => $brokenCount,
        ]);
    }

    /**
     * Lists indexed entries that have no inbound internal links.
     */
    public function actionOrphans(): Response
    {
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $sectionHandle = Http::request()->getQueryParam('section');
        $orphans = Plugin::$plugin->links->reports->getOrphanPages($siteId, $sectionHandle);

        if ($this->wantsCsv()) {
            $rows = array_map(static fn($o) => [$o['title'], $o['sectionName'], $o['outboundCount'], $o['dateCreated']], $orphans);
            return $this->csvResponse('orphan-pages.csv', ['Title', 'Section', 'Outbound Links', 'Date Created'], $rows);
        }

        return $this->renderTemplate('beacon/links/orphans', [
            'orphans' => $orphans,
            'sections' => Craft::$app->getEntries()->getAllSections(),
            'selectedSection' => $sectionHandle,
        ]);
    }

    /**
     * Renders the inbound/outbound link-count map for every indexed entry.
     */
    public function actionLinkMap(): Response
    {
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $sectionHandle = Http::request()->getQueryParam('section');
        $linkMap = Plugin::$plugin->links->reports->getLinkMap($siteId, $sectionHandle);

        if ($this->wantsCsv()) {
            $rows = array_map(static fn($r) => [$r['title'], $r['sectionName'], $r['inboundCount'], $r['outboundCount']], $linkMap);
            return $this->csvResponse('link-map.csv', ['Title', 'Section', 'Inbound Links', 'Outbound Links'], $rows);
        }

        return $this->renderTemplate('beacon/links/link-map', [
            'linkMap' => $linkMap,
            'sections' => Craft::$app->getEntries()->getAllSections(),
            'selectedSection' => $sectionHandle,
        ]);
    }

    /**
     * Renders the inbound/outbound link detail for a single entry.
     *
     * @throws NotFoundHttpException when the entry doesn't exist on the current site
     */
    public function actionLinkDetail(): Response
    {
        $entryId = (int) Http::request()->getRequiredQueryParam('entryId');
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        /** @var Entry|null $entry */
        $entry = Entry::find()->id($entryId)->siteId($siteId)->status(null)->one();
        if ($entry === null) {
            throw new NotFoundHttpException('Entry not found');
        }

        $inbound = [];
        $inboundRecords = LinkRecord::find()->where(['targetElementId' => $entryId, 'targetSiteId' => $siteId])->all();
        foreach ($inboundRecords as $record) {
            /** @var LinkRecord $record */
            /** @var Entry|null $source */
            $source = Entry::find()->id($record->sourceElementId)->siteId($record->sourceSiteId)->status(null)->one();
            if ($source !== null) {
                $inbound[] = [
                    'title' => $source->title,
                    'cpEditUrl' => $source->getCpEditUrl(),
                    'url' => $source->getUrl(),
                    'sectionName' => $source->getSection()?->name ?? '(unknown)',
                    'fieldHandle' => $record->fieldHandle,
                ];
            }
        }

        $outbound = [];
        $outboundRecords = LinkRecord::find()->where(['sourceElementId' => $entryId, 'sourceSiteId' => $siteId])->all();
        foreach ($outboundRecords as $record) {
            /** @var LinkRecord $record */
            if ($record->targetElementId === null) {
                continue;
            }
            /** @var Entry|null $target */
            $target = Entry::find()->id($record->targetElementId)->siteId($record->targetSiteId)->status(null)->one();
            if ($target !== null) {
                $outbound[] = [
                    'title' => $target->title,
                    'cpEditUrl' => $target->getCpEditUrl(),
                    'url' => $target->getUrl(),
                    'sectionName' => $target->getSection()?->name ?? '(unknown)',
                    'fieldHandle' => $record->fieldHandle,
                ];
            }
        }

        if ($this->wantsCsv()) {
            $rows = [];
            foreach ($inbound as $link) {
                $rows[] = ['Inbound', $link['title'], $link['sectionName'], $link['fieldHandle']];
            }
            foreach ($outbound as $link) {
                $rows[] = ['Outbound', $link['title'], $link['sectionName'], $link['fieldHandle']];
            }
            return $this->csvResponse('link-detail-' . $entry->slug . '.csv', ['Direction', 'Title', 'Section', 'Field'], $rows);
        }

        return $this->renderTemplate('beacon/links/link-detail', [
            'entry' => $entry,
            'inbound' => $inbound,
            'outbound' => $outbound,
        ]);
    }

    /**
     * Renders accepted/dismissed suggestion totals plus the recently accepted
     * suggestions.
     */
    public function actionSuggestions(): Response
    {
        // Query across all sites — suggestions may be recorded with different siteIds.
        $accepted = (int) LinkSuggestionRecord::find()->where(['status' => 'accepted'])->count();
        $dismissed = (int) LinkSuggestionRecord::find()->where(['status' => 'dismissed'])->count();
        $recentAccepted = LinkSuggestionRecord::find()
            ->where(['status' => 'accepted'])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit(20)
            ->all();

        $recentRows = [];
        foreach ($recentAccepted as $record) {
            /** @var LinkSuggestionRecord $record */
            /** @var Entry|null $source */
            $source = Entry::find()->id($record->sourceElementId)->siteId('*')->status(null)->one();
            /** @var Entry|null $target */
            $target = Entry::find()->id($record->targetElementId)->siteId('*')->status(null)->one();
            if ($source !== null && $target !== null) {
                $recentRows[] = [
                    'sourceTitle' => $source->title,
                    'sourceCpUrl' => $source->getCpEditUrl(),
                    'targetTitle' => $target->title,
                    'targetCpUrl' => $target->getCpEditUrl(),
                    'score' => round($record->score, 3),
                    'date' => $record->dateCreated,
                ];
            }
        }

        if ($this->wantsCsv()) {
            $rows = array_map(static fn($r) => [$r['sourceTitle'], $r['targetTitle'], $r['score'], $r['date']], $recentRows);
            return $this->csvResponse('suggestions.csv', ['Source', 'Target', 'Score', 'Date'], $rows);
        }

        return $this->renderTemplate('beacon/links/suggestions', [
            'accepted' => $accepted,
            'dismissed' => $dismissed,
            'rate' => Plugin::$plugin->links->reports->calculateAcceptanceRate($accepted, $dismissed),
            'recent' => $recentRows,
        ]);
    }

    /**
     * Renders click depth (BFS distance from the homepage) for every reachable
     * entry, plus the unreachable set.
     */
    public function actionClickDepth(): Response
    {
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $result = Plugin::$plugin->links->depth->calculateDepths($siteId);

        $depths = $result['depths'];
        $unreachableIds = $result['unreachable'];

        $allIds = array_merge(array_keys($depths), $unreachableIds);
        $entryMap = [];
        if ($allIds !== []) {
            foreach (Entry::find()->id($allIds)->siteId($siteId)->status(null)->all() as $entry) {
                $entryMap[$entry->id] = $entry;
            }
        }

        $inboundCounts = [];
        if ($allIds !== []) {
            $rows = LinkRecord::find()
                ->select(['targetElementId', new \yii\db\Expression('COUNT(*) as cnt')])
                ->where(['targetElementId' => $allIds, 'targetSiteId' => $siteId])
                ->groupBy('targetElementId')
                ->asArray()
                ->all();
            foreach ($rows as $row) {
                $inboundCounts[(int) $row['targetElementId']] = (int) $row['cnt'];
            }
        }

        $entries = [];
        foreach ($depths as $elementId => $depth) {
            $entry = $entryMap[$elementId] ?? null;
            if ($entry === null) {
                continue;
            }
            $entries[] = [
                'elementId' => $elementId,
                'title' => $entry->title,
                'sectionName' => $entry->getSection()?->name ?? '(unknown)',
                'cpEditUrl' => $entry->getCpEditUrl(),
                'depth' => $depth,
                'inboundCount' => $inboundCounts[$elementId] ?? 0,
            ];
        }
        usort($entries, static fn($a, $b) => $b['depth'] <=> $a['depth']);

        $unreachable = [];
        foreach ($unreachableIds as $elementId) {
            $entry = $entryMap[$elementId] ?? null;
            if ($entry === null) {
                continue;
            }
            $unreachable[] = [
                'elementId' => $elementId,
                'title' => $entry->title,
                'sectionName' => $entry->getSection()?->name ?? '(unknown)',
                'cpEditUrl' => $entry->getCpEditUrl(),
                'inboundCount' => $inboundCounts[$elementId] ?? 0,
            ];
        }

        if ($this->wantsCsv()) {
            $rows = array_map(static fn($e) => [$e['title'], $e['sectionName'], $e['depth'], $e['inboundCount']], $entries);
            return $this->csvResponse('click-depth.csv', ['Title', 'Section', 'Depth', 'Inbound Links'], $rows);
        }

        return $this->renderTemplate('beacon/links/click-depth', [
            'entries' => $entries,
            'unreachable' => $unreachable,
        ]);
    }

    /**
     * Lists internal links whose target is deleted/disabled and external links
     * that failed their HTTP audit.
     */
    public function actionBrokenLinks(): Response
    {
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $broken = Plugin::$plugin->links->brokenLinks->findBroken($siteId);

        $enriched = [];
        foreach ($broken as $link) {
            /** @var Entry|null $source */
            $source = Entry::find()->id($link['sourceElementId'])->siteId($siteId)->status(null)->one();
            if ($source === null) {
                continue;
            }
            $enriched[] = [
                'sourceTitle' => $source->title,
                'sourceCpEditUrl' => $source->getCpEditUrl(),
                'targetUrl' => $link['targetUrl'] ?? '(unknown)',
                'status' => $link['status'],
                'httpStatus' => $link['httpStatus'],
                'httpCheckedAt' => $link['httpCheckedAt'],
            ];
        }

        if ($this->wantsCsv()) {
            $rows = array_map(static fn($r) => [$r['sourceTitle'], $r['targetUrl'], $r['status'], $r['httpStatus'] ?? '', $r['httpCheckedAt'] ?? ''], $enriched);
            return $this->csvResponse('broken-links.csv', ['Source', 'Target URL', 'Status', 'HTTP Code', 'Last Checked'], $rows);
        }

        return $this->renderTemplate('beacon/links/broken-links', [
            'links' => $enriched,
        ]);
    }

    /**
     * Lists internal links whose anchor text matches a generic/non-descriptive
     * pattern.
     */
    public function actionAnchorText(): Response
    {
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $settings = Plugin::$plugin->links->getSettings();
        $generic = Plugin::$plugin->links->anchorText->findGenericAnchors($siteId, $settings->genericAnchorPatterns);

        $enriched = [];
        foreach ($generic as $item) {
            /** @var Entry|null $source */
            $source = Entry::find()->id($item['sourceElementId'])->siteId($siteId)->status(null)->one();
            if ($source === null) {
                continue;
            }
            $targetTitle = null;
            $targetCpEditUrl = null;
            if ($item['targetElementId'] !== null) {
                /** @var Entry|null $target */
                $target = Entry::find()->id($item['targetElementId'])->siteId($siteId)->status(null)->one();
                if ($target !== null) {
                    $targetTitle = $target->title;
                    $targetCpEditUrl = $target->getCpEditUrl();
                }
            }
            $enriched[] = [
                'sourceTitle' => $source->title,
                'sourceCpEditUrl' => $source->getCpEditUrl(),
                'targetTitle' => $targetTitle,
                'targetCpEditUrl' => $targetCpEditUrl,
                'anchorText' => $item['anchorText'],
                'fieldHandle' => $item['fieldHandle'],
            ];
        }

        if ($this->wantsCsv()) {
            $rows = array_map(static fn($r) => [$r['sourceTitle'], $r['targetTitle'] ?? '', $r['anchorText'], $r['fieldHandle']], $enriched);
            return $this->csvResponse('anchor-text.csv', ['Source', 'Target', 'Anchor Text', 'Field'], $rows);
        }

        return $this->renderTemplate('beacon/links/anchor-text', [
            'links' => $enriched,
        ]);
    }

    /**
     * Lists outbound external links with their last HTTP audit result.
     */
    public function actionExternalLinks(): Response
    {
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $records = LinkRecord::find()->where(['sourceSiteId' => $siteId, 'isExternal' => true])->all();

        $enriched = [];
        foreach ($records as $record) {
            /** @var LinkRecord $record */
            /** @var Entry|null $source */
            $source = Entry::find()->id($record->sourceElementId)->siteId($siteId)->status(null)->one();
            if ($source === null) {
                continue;
            }
            $enriched[] = [
                'sourceTitle' => $source->title,
                'sourceCpEditUrl' => $source->getCpEditUrl(),
                'url' => $record->targetUrl,
                'anchorText' => $record->anchorText,
                'httpStatus' => $record->httpStatus !== null ? (int) $record->httpStatus : null,
                'httpCheckedAt' => $record->httpCheckedAt,
            ];
        }

        if ($this->wantsCsv()) {
            $rows = array_map(static fn($r) => [$r['sourceTitle'], $r['url'] ?? '', $r['anchorText'] ?? '', $r['httpStatus'] ?? '', $r['httpCheckedAt'] ?? ''], $enriched);
            return $this->csvResponse('external-links.csv', ['Source', 'URL', 'Anchor Text', 'HTTP Status', 'Last Checked'], $rows);
        }

        return $this->renderTemplate('beacon/links/external-links', [
            'links' => $enriched,
        ]);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Builds a downloadable CSV response from the given headers and rows.
     *
     * @param list<string> $headers
     * @param array<int, array<int|string, mixed>> $rows
     */
    private function csvResponse(string $filename, array $headers, array $rows): Response
    {
        $csv = Plugin::$plugin->links->export->toCsv($headers, $rows);
        $response = Http::response();
        $response->content = $csv;
        $response->setDownloadHeaders($filename, 'text/csv');
        return $response;
    }

    /**
     * Whether the request asked for the CSV representation (`?format=csv`).
     */
    private function wantsCsv(): bool
    {
        return Http::request()->getQueryParam('format') === 'csv';
    }
}
