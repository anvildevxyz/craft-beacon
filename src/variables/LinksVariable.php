<?php

namespace anvildev\beacon\variables;

use anvildev\beacon\helpers\links\TfIdfScorer;
use anvildev\beacon\Plugin;
use anvildev\beacon\records\LinkRecord;
use anvildev\beacon\records\LinkSuggestionRecord;
use Craft;
use craft\elements\Entry;

/**
 * Template variable for the Links (internal-link-graph) feature, exposed as
 * `craft.beacon.links`. Surfaces link suggestions and the recorded link graph
 * for an entry to front-end and CP templates.
 *
 * Ported from Whisper's `WhisperVariable`.
 *
 * @author Anvil
 * @since 1.0.0
 */
class LinksVariable
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Returns link suggestions for an entry.
     *
     * By default only suggestions targeting live/enabled entries are returned.
     * Pass $includeNonLive = true to include disabled or expired entries (e.g.
     * in CP previews where non-live content should be visible).
     *
     * @return list<array{targetElementId: int, score: float}>
     */
    public function suggestionsFor(Entry|int $entry, ?int $siteId = null, bool $includeNonLive = false): array
    {
        if (!$this->featureEnabled()) {
            return [];
        }

        $entryId = $entry instanceof Entry ? $entry->id : $entry;
        $siteId = $siteId ?? ($entry instanceof Entry ? $entry->siteId : Craft::$app->getSites()->getCurrentSite()->id);
        if ($entryId === null) {
            return [];
        }

        $links = Plugin::$plugin->links;
        $settings = $links->getSettings();

        $results = $links->suggestions->getCachedOrCompute(
            $entryId,
            $siteId,
            $settings->reportCacheDuration,
            function() use ($links, $settings, $entryId, $siteId): array {
                $corpus = $links->index->loadCorpus($siteId);
                $sourceKeywords = $corpus[$entryId] ?? [];
                if ($sourceKeywords === []) {
                    return [];
                }
                $linkedIds = $links->linkScan->getLinkedElementIds($entryId, $siteId);
                $interactedIds = $links->suggestions->getInteractedElementIds($entryId, $siteId);
                $excludeIds = array_unique(array_merge([$entryId], $linkedIds, $interactedIds));
                $scorer = new TfIdfScorer($corpus, $settings->maxDocumentFrequencyRatio);
                $keywordResults = $scorer->scoreAll($sourceKeywords, $corpus, $excludeIds);
                $embeddingResults = [];
                if ($settings->embeddingsEnabled) {
                    $sourceEmbedding = $links->embedding->getEmbedding($entryId, $siteId);
                    if ($sourceEmbedding !== null) {
                        $embeddingResults = $links->embedding->scoreAll($sourceEmbedding, $siteId, $excludeIds);
                    }
                }
                $merged = $links->suggestions->mergeResults($keywordResults, $embeddingResults);
                $filtered = $links->suggestions->filterByMinScore($merged, $settings->minScore);
                return $links->suggestions->limitResults($filtered, $settings->maxSuggestions);
            },
        );

        $suggestions = array_map(
            static fn(array $r): array => [
                'targetElementId' => (int) $r['elementId'],
                'score' => (float) $r['score'],
            ],
            $results,
        );

        if (!$includeNonLive && $suggestions !== []) {
            $ids = array_column($suggestions, 'targetElementId');
            $liveIds = array_flip(
                Entry::find()->id($ids)->siteId($siteId)->status('live')->ids(),
            );
            $suggestions = array_values(array_filter(
                $suggestions,
                static fn(array $s): bool => isset($liveIds[$s['targetElementId']]),
            ));
        }

        return $suggestions;
    }

    /**
     * Returns inbound internal links pointing at this entry.
     *
     * By default only links from live/enabled source entries are returned.
     * Pass $includeNonLive = true to include links from disabled or expired
     * source entries.
     *
     * @return list<array{sourceElementId: int, fieldHandle: string, anchorText: ?string, targetElementType: ?string}>
     */
    public function inboundLinks(Entry|int $entry, ?int $siteId = null, bool $includeNonLive = false): array
    {
        if (!$this->featureEnabled()) {
            return [];
        }

        $entryId = $entry instanceof Entry ? $entry->id : $entry;
        $siteId = $siteId ?? ($entry instanceof Entry ? $entry->siteId : Craft::$app->getSites()->getCurrentSite()->id);
        if ($entryId === null) {
            return [];
        }
        /** @var LinkRecord[] $rows */
        $rows = LinkRecord::find()
            ->where(['targetElementId' => $entryId, 'targetSiteId' => $siteId, 'isExternal' => false, 'ignored' => false])
            ->all();

        $links = array_map(
            static fn(LinkRecord $r): array => [
                'sourceElementId' => (int) $r->sourceElementId,
                'fieldHandle' => (string) $r->fieldHandle,
                'anchorText' => $r->anchorText,
                'targetElementType' => $r->targetElementType,
            ],
            $rows,
        );

        if (!$includeNonLive && $links !== []) {
            $sourceIds = array_column($links, 'sourceElementId');
            $liveIds = array_flip(
                Entry::find()->id($sourceIds)->siteId($siteId)->status('live')->ids(),
            );
            $links = array_values(array_filter(
                $links,
                static fn(array $l): bool => isset($liveIds[$l['sourceElementId']]),
            ));
        }

        return $links;
    }

    /**
     * Returns outbound links from this entry (internal + external).
     *
     * By default, links to non-live Entry targets are filtered out. Links to
     * external URLs or non-Entry element types (assets, categories, etc.) are
     * always included regardless of this flag. Pass $includeNonLive = true to
     * also include links whose Entry target is disabled or expired.
     *
     * @return list<array{targetElementId: ?int, targetElementType: ?string, targetUrl: ?string, fieldHandle: string, anchorText: ?string, isExternal: bool}>
     */
    public function outboundLinks(Entry|int $entry, ?int $siteId = null, bool $includeNonLive = false): array
    {
        if (!$this->featureEnabled()) {
            return [];
        }

        $entryId = $entry instanceof Entry ? $entry->id : $entry;
        $siteId = $siteId ?? ($entry instanceof Entry ? $entry->siteId : Craft::$app->getSites()->getCurrentSite()->id);
        if ($entryId === null) {
            return [];
        }
        /** @var LinkRecord[] $rows */
        $rows = LinkRecord::find()
            ->where(['sourceElementId' => $entryId, 'sourceSiteId' => $siteId, 'ignored' => false])
            ->all();

        $links = array_map(
            static fn(LinkRecord $r): array => [
                'targetElementId' => $r->targetElementId !== null ? (int) $r->targetElementId : null,
                'targetElementType' => $r->targetElementType,
                'targetUrl' => $r->targetUrl,
                'fieldHandle' => (string) $r->fieldHandle,
                'anchorText' => $r->anchorText,
                'isExternal' => (bool) $r->isExternal,
            ],
            $rows,
        );

        if (!$includeNonLive) {
            $entryTargetIds = [];
            foreach ($links as $link) {
                if (!$link['isExternal'] && $link['targetElementType'] === Entry::class && $link['targetElementId'] !== null) {
                    $entryTargetIds[] = $link['targetElementId'];
                }
            }
            if ($entryTargetIds !== []) {
                $liveIds = array_flip(
                    Entry::find()->id(array_unique($entryTargetIds))->siteId($siteId)->status('live')->ids(),
                );
                $links = array_values(array_filter(
                    $links,
                    static function(array $l) use ($liveIds): bool {
                        // Keep external links and non-Entry internal links unconditionally.
                        if ($l['isExternal'] || $l['targetElementType'] !== Entry::class || $l['targetElementId'] === null) {
                            return true;
                        }
                        return isset($liveIds[$l['targetElementId']]);
                    },
                ));
            }
        }

        return $links;
    }

    /**
     * Returns outbound links from this entry filtered by target element type.
     *
     * When $elementType is Entry::class and $includeNonLive is false (the
     * default), only links to live/enabled entries are returned. For other
     * element types the $includeNonLive flag has no effect (non-entry elements
     * pass through unconditionally).
     *
     * @return list<array{targetElementId: ?int, targetElementType: ?string, targetUrl: ?string, anchorText: ?string}>
     */
    public function outboundLinksByType(Entry|int $entry, string $elementType, ?int $siteId = null, bool $includeNonLive = false): array
    {
        if (!$this->featureEnabled()) {
            return [];
        }

        $entryId = $entry instanceof Entry ? $entry->id : $entry;
        $siteId = $siteId ?? ($entry instanceof Entry ? $entry->siteId : Craft::$app->getSites()->getCurrentSite()->id);
        if ($entryId === null) {
            return [];
        }
        /** @var LinkRecord[] $rows */
        $rows = LinkRecord::find()
            ->where(['sourceElementId' => $entryId, 'sourceSiteId' => $siteId, 'targetElementType' => $elementType, 'ignored' => false])
            ->all();

        $links = array_map(
            static fn(LinkRecord $r): array => [
                'targetElementId' => $r->targetElementId !== null ? (int) $r->targetElementId : null,
                'targetElementType' => $r->targetElementType,
                'targetUrl' => $r->targetUrl,
                'anchorText' => $r->anchorText,
            ],
            $rows,
        );

        if (!$includeNonLive && $elementType === Entry::class && $links !== []) {
            $targetIds = array_filter(array_column($links, 'targetElementId'));
            if ($targetIds !== []) {
                $liveIds = array_flip(
                    Entry::find()->id(array_unique($targetIds))->siteId($siteId)->status('live')->ids(),
                );
                $links = array_values(array_filter(
                    $links,
                    static fn(array $l): bool => $l['targetElementId'] === null || isset($liveIds[$l['targetElementId']]),
                ));
            }
        }

        return $links;
    }

    /**
     * Returns interaction status for a (source, target, site) triple, or null
     * if none has been recorded.
     */
    public function interactionStatus(int $sourceId, int $targetId, ?int $siteId = null): ?string
    {
        if (!$this->featureEnabled()) {
            return null;
        }

        $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $record = LinkSuggestionRecord::findOne([
            'sourceElementId' => $sourceId,
            'targetElementId' => $targetId,
            'siteId' => $siteId,
        ]);
        return $record?->status;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    private function featureEnabled(): bool
    {
        return Plugin::$plugin->links->isEnabled();
    }
}
