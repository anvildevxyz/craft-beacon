<?php

namespace anvildev\beacon\services\links;

use anvildev\beacon\events\LinkBrokenCheckEvent;
use anvildev\beacon\records\LinkRecord;
use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\db\Table;
use yii\base\Event;

class BrokenLinkService extends Component
{
    public const EVENT_BROKEN_LINK_CHECKED = 'brokenLinkChecked';

    /**
     * Find broken internal links for a site.
     * A link is broken if the target element no longer exists or is disabled.
     *
     * Processes records in batches of 500 to avoid loading all rows into memory,
     * and resolves element status with a single batch query per batch (no N+1).
     *
     * @return array<int, array{id: int, sourceElementId: int, targetElementId: int|null, targetElementType: string|null, targetUrl: string|null, fieldHandle: string, anchorText: string|null, status: string, httpStatus: int|null, httpCheckedAt: string|null}>
     */
    public function findBroken(int $siteId): array
    {
        $broken = [];

        $baseQuery = LinkRecord::find()
            ->where(['sourceSiteId' => $siteId, 'isExternal' => false, 'ignored' => false])
            ->andWhere(['not', ['targetElementId' => null]]);

        foreach ($baseQuery->batch(500) as $batch) {
            // Collect distinct target IDs for this batch to avoid redundant lookups.
            $rawIds = [];
            foreach ($batch as $batchRecord) {
                /** @var LinkRecord $batchRecord */
                if ($batchRecord->targetElementId !== null) {
                    $rawIds[] = (int) $batchRecord->targetElementId;
                }
            }
            $targetIds = array_values(array_unique($rawIds));

            // One query resolves existence and enabled state for all targets in the batch.
            // An element is considered enabled when both the element row and its site row
            // have enabled = 1 and the element has not been soft-deleted.
            //
            // Assets: craft\elements\Asset::isLocalized() returns true, which means Craft
            // propagates assets to every site and creates an elements_sites row for each one.
            // The INNER JOIN on elements_sites with siteId = :siteId therefore correctly
            // finds asset targets — no special-casing is needed for asset element types.
            $enabledMap = [];
            if ($targetIds !== []) {
                $rows = (new Query())
                    ->select(['e.id', 'e.enabled as elementEnabled', 'es.enabled as siteEnabled'])
                    ->from(['e' => Table::ELEMENTS])
                    ->innerJoin(['es' => Table::ELEMENTS_SITES], '[[es.elementId]] = [[e.id]]')
                    ->where([
                        'e.id' => $targetIds,
                        'es.siteId' => $siteId,
                        'e.dateDeleted' => null,
                    ])
                    ->all();

                foreach ($rows as $row) {
                    // Cast DB booleans: in Craft 5 these may be stored as ints (0/1).
                    $enabledMap[(int) $row['id']] = ((bool) $row['elementEnabled']) && ((bool) $row['siteEnabled']);
                }
            }

            foreach ($batch as $record) {
                /** @var LinkRecord $record */
                $targetId = $record->targetElementId !== null ? (int) $record->targetElementId : null;

                if ($targetId === null || !array_key_exists($targetId, $enabledMap)) {
                    // Element not found in the site (deleted or not propagated here).
                    $enabled = null;
                } else {
                    $enabled = $enabledMap[$targetId];
                }

                $status = $this->classifyStatus($enabled, $record->httpStatus);

                $checkEvent = new LinkBrokenCheckEvent();
                $checkEvent->link = $record;
                $checkEvent->httpStatus = $record->httpStatus !== null ? (int) $record->httpStatus : null;
                $checkEvent->isBroken = $status !== 'ok';
                Event::trigger(self::class, self::EVENT_BROKEN_LINK_CHECKED, $checkEvent);

                if ($status !== 'ok') {
                    $broken[] = [
                        'id' => (int) $record->id,
                        'sourceElementId' => (int) $record->sourceElementId,
                        'targetElementId' => $targetId,
                        'targetElementType' => $record->targetElementType,
                        'targetUrl' => $record->targetUrl,
                        'fieldHandle' => $record->fieldHandle,
                        'anchorText' => $record->anchorText,
                        'status' => $status,
                        'httpStatus' => $record->httpStatus !== null ? (int) $record->httpStatus : null,
                        'httpCheckedAt' => $record->httpCheckedAt,
                    ];
                }
            }
        }

        return $broken;
    }

    /**
     * Find broken external links for a site (those with non-2xx HTTP status).
     *
     * @return array<int, array{id: int, sourceElementId: int, targetElementId: int|null, targetElementType: string|null, targetUrl: string|null, fieldHandle: string, anchorText: string|null, status: string, httpStatus: int|null, httpCheckedAt: string|null}>
     */
    public function findBrokenExternal(int $siteId): array
    {
        $records = LinkRecord::find()
            ->where(['sourceSiteId' => $siteId, 'isExternal' => true, 'ignored' => false])
            ->andWhere(['not', ['httpStatus' => null]])
            ->andWhere(['not', ['targetUrl' => null]])
            ->all();

        $broken = [];
        foreach ($records as $record) {
            /** @var LinkRecord $record */
            $httpStatus = $record->httpStatus !== null ? (int) $record->httpStatus : null;
            if ($httpStatus === null || ($httpStatus >= 200 && $httpStatus < 300)) {
                continue;
            }

            $broken[] = [
                'id' => (int) $record->id,
                'sourceElementId' => (int) $record->sourceElementId,
                'targetElementId' => $record->targetElementId !== null ? (int) $record->targetElementId : null,
                'targetElementType' => $record->targetElementType,
                'targetUrl' => $record->targetUrl,
                'fieldHandle' => $record->fieldHandle,
                'anchorText' => $record->anchorText,
                'status' => 'http_error',
                'httpStatus' => $httpStatus,
                'httpCheckedAt' => $record->httpCheckedAt,
            ];
        }

        return $broken;
    }

    /**
     * Classify a link's status based on element enabled state and HTTP status.
     *
     * Returns:
     *  - 'deleted'    when $enabled is null (element not found)
     *  - 'disabled'   when $enabled is false
     *  - 'http_error' when $enabled is true and $httpStatus is non-2xx
     *  - 'ok'         when $enabled is true and $httpStatus is null or 2xx
     */
    public function classifyStatus(?bool $enabled, ?int $httpStatus): string
    {
        if ($enabled === null) {
            return 'deleted';
        }

        if ($enabled === false) {
            return 'disabled';
        }

        // enabled is true
        if ($httpStatus !== null && ($httpStatus < 200 || $httpStatus >= 300)) {
            return 'http_error';
        }

        return 'ok';
    }

    /**
     * Check if an anchor text matches any generic/non-descriptive patterns (case-insensitive).
     *
     * @param string[] $patterns
     */
    public function isGenericAnchor(string $anchorText, array $patterns): bool
    {
        if ($anchorText === '') {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (strcasecmp($anchorText, $pattern) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mark a link as ignored so it won't appear in broken link reports.
     */
    public function markIgnored(int $linkId): void
    {
        $record = LinkRecord::findOne($linkId);
        if ($record !== null) {
            $record->ignored = true;
            $record->save();
        }
    }
}
