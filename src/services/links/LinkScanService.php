<?php

namespace anvildev\beacon\services\links;

use anvildev\beacon\helpers\links\LinkParser;
use anvildev\beacon\records\LinkRecord;
use craft\base\Component;

class LinkScanService extends Component
{
    /**
     * @param array<string, string> $fields
     * @return array<int, array{url: string, fieldHandle: string, anchorText: string, isExternal: bool}>
     */
    public function extractLinksFromFields(array $fields, string $siteUrl): array
    {
        $results = [];
        foreach ($fields as $handle => $html) {
            $links = LinkParser::extractLinks($html, $siteUrl);
            foreach ($links as $link) {
                $results[] = [
                    'url' => $link['url'],
                    'fieldHandle' => $handle,
                    'anchorText' => $link['anchorText'],
                    'isExternal' => $link['isExternal'],
                ];
            }
        }
        return $results;
    }

    /** @param array<int, array{targetElementId: int|null, targetSiteId: int|null, targetElementType?: string|null, fieldHandle: string, anchorText?: string, isExternal?: bool, targetUrl?: string|null}> $links */
    public function saveLinks(int $sourceElementId, int $sourceSiteId, array $links): void
    {
        $transaction = \Craft::$app->getDb()->beginTransaction();
        try {
            LinkRecord::deleteAll(['sourceElementId' => $sourceElementId, 'sourceSiteId' => $sourceSiteId]);
            foreach ($links as $link) {
                $record = new LinkRecord();
                $record->sourceElementId = $sourceElementId;
                $record->sourceSiteId = $sourceSiteId;
                $record->targetElementId = $link['targetElementId'];
                $record->targetSiteId = $link['targetSiteId'];
                $record->targetElementType = $link['targetElementType'] ?? null;
                $record->fieldHandle = $link['fieldHandle'];
                $record->anchorText = $link['anchorText'] ?? null;
                $record->isExternal = $link['isExternal'] ?? false;
                $record->targetUrl = $link['targetUrl'] ?? null;
                $record->save();
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /** @return int[] */
    public function getLinkedElementIds(int $sourceElementId, int $sourceSiteId): array
    {
        return LinkRecord::find()
            ->where(['sourceElementId' => $sourceElementId, 'sourceSiteId' => $sourceSiteId])
            ->select('targetElementId')
            ->column();
    }

    /**
     * Delete all link rows where the given element is the source or target, across ALL sites.
     * Called from the Entry::EVENT_AFTER_DELETE handler when an element is fully removed,
     * so cross-site deletion is intentional.
     *
     * @param int $elementId The element ID whose rows should be removed.
     */
    public function deleteByElementId(int $elementId): void
    {
        LinkRecord::deleteAll(['sourceElementId' => $elementId]);
        LinkRecord::deleteAll(['targetElementId' => $elementId]);
    }

    public function clearAll(): void
    {
        LinkRecord::deleteAll();
    }

    public function countInboundLinks(int $targetElementId): int
    {
        return (int) LinkRecord::find()->where(['targetElementId' => $targetElementId])->count();
    }

    public function countOutboundLinks(int $sourceElementId): int
    {
        return (int) LinkRecord::find()->where(['sourceElementId' => $sourceElementId])->count();
    }
}
