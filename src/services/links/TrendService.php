<?php

namespace anvildev\beacon\services\links;

use anvildev\beacon\events\LinkSnapshotRecordedEvent;
use anvildev\beacon\records\LinkSnapshotRecord;
use craft\base\Component;
use DateTime;
use yii\base\Event;

class TrendService extends Component
{
    public const EVENT_SNAPSHOT_RECORDED = 'snapshotRecorded';

    /**
     * Build a snapshot data array from the given stats.
     *
     * @return array{orphanCount: int, avgLinksPerPage: float, totalInternalLinks: int, brokenLinkCount: int, indexedEntryCount: int}
     */
    public function buildSnapshotData(int $orphanCount, float $avgLinksPerPage, int $totalInternalLinks, int $brokenLinkCount, int $indexedEntryCount): array
    {
        return [
            'orphanCount' => $orphanCount,
            'avgLinksPerPage' => $avgLinksPerPage,
            'totalInternalLinks' => $totalInternalLinks,
            'brokenLinkCount' => $brokenLinkCount,
            'indexedEntryCount' => $indexedEntryCount,
        ];
    }

    /**
     * Upsert a snapshot record for the given site and today's date.
     *
     * @param array{orphanCount: int, avgLinksPerPage: float, totalInternalLinks: int, brokenLinkCount: int, indexedEntryCount: int} $data
     */
    public function recordSnapshot(int $siteId, array $data): void
    {
        $today = (new DateTime())->format('Y-m-d');

        $record = LinkSnapshotRecord::findOne(['siteId' => $siteId, 'snapshotDate' => $today]);
        if ($record !== null) {
            $record->orphanCount = $data['orphanCount'];
            $record->avgLinksPerPage = $data['avgLinksPerPage'];
            $record->totalInternalLinks = $data['totalInternalLinks'];
            $record->brokenLinkCount = $data['brokenLinkCount'];
            $record->indexedEntryCount = $data['indexedEntryCount'];
            $record->save();

            $event = new LinkSnapshotRecordedEvent();
            $event->siteId = $siteId;
            $event->snapshotDate = $today;
            $event->totalLinks = $data['totalInternalLinks'];
            $event->brokenLinks = $data['brokenLinkCount'];
            Event::trigger(self::class, self::EVENT_SNAPSHOT_RECORDED, $event);
            return;
        }

        try {
            $record = new LinkSnapshotRecord();
            $record->siteId = $siteId;
            $record->snapshotDate = $today;
            $record->orphanCount = $data['orphanCount'];
            $record->avgLinksPerPage = $data['avgLinksPerPage'];
            $record->totalInternalLinks = $data['totalInternalLinks'];
            $record->brokenLinkCount = $data['brokenLinkCount'];
            $record->indexedEntryCount = $data['indexedEntryCount'];
            $record->save();
        } catch (\yii\db\IntegrityException) {
            // Lost the race — another process inserted first; update that row instead
            $record = LinkSnapshotRecord::findOne(['siteId' => $siteId, 'snapshotDate' => $today]);
            if ($record !== null) {
                $record->orphanCount = $data['orphanCount'];
                $record->avgLinksPerPage = $data['avgLinksPerPage'];
                $record->totalInternalLinks = $data['totalInternalLinks'];
                $record->brokenLinkCount = $data['brokenLinkCount'];
                $record->indexedEntryCount = $data['indexedEntryCount'];
                $record->save();
            }
        }

        $event = new LinkSnapshotRecordedEvent();
        $event->siteId = $siteId;
        $event->snapshotDate = $today;
        $event->totalLinks = $data['totalInternalLinks'];
        $event->brokenLinks = $data['brokenLinkCount'];
        Event::trigger(self::class, self::EVENT_SNAPSHOT_RECORDED, $event);
    }

    /**
     * Return recent snapshot records for a site, ordered by date descending.
     *
     * @return array<int, array{
     *   id: int,
     *   siteId: int,
     *   snapshotDate: string,
     *   orphanCount: int,
     *   avgLinksPerPage: float,
     *   totalInternalLinks: int,
     *   brokenLinkCount: int,
     *   indexedEntryCount: int,
     *   dateCreated: string,
     *   dateUpdated: string,
     *   uid: string
     * }>
     */
    public function getRecentSnapshots(int $siteId, int $days = 30): array
    {
        /** @var LinkSnapshotRecord[] $records */
        $records = LinkSnapshotRecord::find()
            ->where(['siteId' => $siteId])
            ->orderBy(['snapshotDate' => SORT_DESC])
            ->limit($days)
            ->all();

        return array_map(fn(LinkSnapshotRecord $r): array => [
            'id' => (int) $r->id,
            'siteId' => (int) $r->siteId,
            'snapshotDate' => (string) $r->snapshotDate,
            'orphanCount' => (int) $r->orphanCount,
            'avgLinksPerPage' => (float) $r->avgLinksPerPage,
            'totalInternalLinks' => (int) $r->totalInternalLinks,
            'brokenLinkCount' => (int) $r->brokenLinkCount,
            'indexedEntryCount' => (int) $r->indexedEntryCount,
            'dateCreated' => $r->dateCreated instanceof \DateTimeInterface ? $r->dateCreated->format('Y-m-d H:i:s') : (string) ($r->dateCreated ?? ''),
            'dateUpdated' => $r->dateUpdated instanceof \DateTimeInterface ? $r->dateUpdated->format('Y-m-d H:i:s') : (string) ($r->dateUpdated ?? ''),
            'uid' => (string) $r->uid,
        ], $records);
    }
}
