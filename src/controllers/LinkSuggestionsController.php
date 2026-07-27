<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\helpers\links\TfIdfScorer;
use anvildev\beacon\Plugin;
use Craft;
use craft\elements\Entry;
use craft\errors\InvalidFieldException;
use craft\web\Controller;
use yii\base\Action;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * JSON API backing the entry-sidebar link-suggestion panel and the CKEditor
 * insert / highlight flows (ported from Whisper's `SuggestionsController`).
 *
 * Reads (get / find-phrase) require {@see BeaconPermissions::VIEW_LINKS}; the
 * mutating endpoints (record-interaction / bulk-update) additionally require
 * {@see BeaconPermissions::EDIT_LINKS} and re-verify the user can save the
 * source entry before recording an interaction. Every action is throttled by a
 * per-user fixed-window rate limiter.
 *
 * @author Anvil
 * @since 1.0.0
 */
class LinkSuggestionsController extends Controller
{
    // =========================================================================
    // Traits
    // =========================================================================

    use BeaconCpPermissionTrait;
    use RequiresLinksEnabledTrait;

    // =========================================================================
    // Const Properties
    // =========================================================================

    protected const BEACON_PERMISSION = BeaconPermissions::VIEW_LINKS;

    // =========================================================================
    // Public Methods
    // =========================================================================

    public function init(): void
    {
        parent::init();
        Craft::$app->getSession()->close();
    }

    /**
     * Gates every action behind the rate limiter, on top of the VIEW_LINKS
     * permission applied by {@see BeaconCpPermissionTrait}.
     *
     * @param Action<Controller> $action
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        if (!$this->requireLinksFeatureEnabled()) {
            return false;
        }
        if (!$this->checkRateLimit()) {
            Http::response()->setStatusCode(429);
            Http::response()->getHeaders()->set('Retry-After', '60');
            $this->asJson(['error' => 'rate_limited', 'message' => 'Too many requests'])->send();
            return false;
        }
        return true;
    }

    /**
     * Returns scored link suggestions for the source entry.
     */
    public function actionGet(): Response
    {
        $this->requireAcceptsJson();
        $entryId = (int) Http::request()->getRequiredQueryParam('entryId');
        $siteId = (int) Http::request()->getRequiredQueryParam('siteId');

        // Verify the requesting user can view the source entry.
        $this->requireViewable($entryId, $siteId);

        $user = Craft::$app->getUser()->getIdentity();
        $links = Plugin::$plugin->links;
        $settings = $links->getSettings();

        $results = $links->suggestions->getCachedOrCompute(
            $entryId,
            $siteId,
            $settings->reportCacheDuration,
            function() use ($links, $settings, $entryId, $siteId) {
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

        // Load corpus for keyword overlap (used for the highlight fallback).
        $corpus = $links->index->loadCorpus($siteId);
        $sourceKeywords = $corpus[$entryId] ?? [];

        $enriched = [];
        foreach ($results as $result) {
            /** @var Entry|null $entry */
            $entry = Entry::find()->id($result['elementId'])->siteId($siteId)->status(null)->one();
            if ($entry === null) {
                continue;
            }
            // Skip target entries the user cannot view — don't throw, just omit.
            if (!Craft::$app->getElements()->canView($entry, $user)) {
                continue;
            }
            $targetKeywords = $corpus[$result['elementId']] ?? [];
            $overlapKeys = array_keys(array_intersect_key($sourceKeywords, $targetKeywords));

            $enriched[] = [
                'elementId' => $entry->id,
                'title' => $entry->title,
                'sectionName' => $entry->getSection()?->name ?? '(unknown)',
                'url' => $entry->getUrl(),
                'cpEditUrl' => $entry->getCpEditUrl(),
                'score' => round($result['score'], 3),
                'keywords' => array_slice($overlapKeys, 0, 10),
            ];
        }

        return $this->asJson(['success' => true, 'suggestions' => $enriched]);
    }

    /**
     * Finds the best anchor phrase in the source entry to link to the target,
     * used by the CKEditor auto-insert flow. Falls back to the best overlapping
     * keyword that actually appears in a CKEditor field.
     */
    public function actionFindPhrase(): Response
    {
        $this->requireAcceptsJson();
        $request = Http::request();
        $sourceId = (int) $request->getRequiredQueryParam('sourceId');
        $targetId = (int) $request->getRequiredQueryParam('targetId');
        $siteId = (int) $request->getRequiredQueryParam('siteId');

        // Verify the user can view the source entry.
        $this->requireViewable($sourceId, $siteId);

        /** @var Entry|null $targetEntry */
        $targetEntry = Entry::find()->id($targetId)->siteId($siteId)->status(null)->one();
        if ($targetEntry === null) {
            return $this->asJson(['success' => false, 'error' => 'Target entry not found']);
        }

        $links = Plugin::$plugin->links;
        $corpus = $links->index->loadCorpus($siteId);
        $settings = $links->getSettings();
        $scorer = new TfIdfScorer($corpus, $settings->maxDocumentFrequencyRatio);

        $result = $links->suggestions->findBestPhrase($sourceId, $targetId, $siteId, $scorer->getIdf());
        if ($result !== null) {
            return $this->asJson([
                'success' => true,
                'phrase' => $result['phrase'],
                'fieldHandle' => $result['fieldHandle'],
                'targetUrl' => $targetEntry->getUrl(),
                'targetTitle' => $targetEntry->title,
            ]);
        }

        // Fallback: find the best overlapping keyword that appears in the source content.
        $sourceKeywords = $corpus[$sourceId] ?? [];
        $targetKeywords = $corpus[$targetId] ?? [];
        $overlapKeys = array_keys(array_intersect_key($sourceKeywords, $targetKeywords));

        // Sort by length desc (multi-word keywords are more specific / better anchor text).
        usort($overlapKeys, static fn(string $a, string $b) => strlen($b) <=> strlen($a));

        /** @var Entry|null $sourceEntry */
        $sourceEntry = Entry::find()->id($sourceId)->siteId($siteId)->status(null)->one();
        if ($sourceEntry !== null) {
            $layout = $sourceEntry->getFieldLayout();
            if ($layout !== null) {
                foreach ($layout->getCustomFields() as $field) {
                    if (!str_contains(mb_strtolower(get_class($field), 'UTF-8'), 'ckeditor')) {
                        continue;
                    }
                    try {
                        $value = $sourceEntry->getFieldValue($field->handle);
                    } catch (InvalidFieldException $e) {
                        Craft::warning("Beacon: Invalid field handle '{$field->handle}' while searching fallback phrase for entry {$sourceId}: {$e->getMessage()}", 'beacon');
                        continue;
                    }
                    $html = is_object($value) && method_exists($value, '__toString') ? (string) $value : (is_string($value) ? $value : '');
                    $text = mb_strtolower(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'UTF-8');

                    foreach ($overlapKeys as $keyword) {
                        if (mb_strlen($keyword, 'UTF-8') < 4) {
                            continue;
                        }
                        if (str_contains($text, mb_strtolower($keyword, 'UTF-8'))) {
                            return $this->asJson([
                                'success' => true,
                                'phrase' => $keyword,
                                'fieldHandle' => $field->handle,
                                'targetUrl' => $targetEntry->getUrl(),
                                'targetTitle' => $targetEntry->title,
                            ]);
                        }
                    }
                }
            }
        }

        return $this->asJson(['success' => false, 'error' => 'No matching phrase found']);
    }

    /**
     * Records an accept/dismiss interaction for a single suggestion.
     *
     * @throws ForbiddenHttpException when the user can't save the source entry
     */
    public function actionRecordInteraction(): Response
    {
        $this->requirePermission(BeaconPermissions::EDIT_LINKS);
        $this->requireAcceptsJson();
        $this->requirePostRequest();
        $request = Http::request();
        $sourceElementId = (int) $request->getRequiredBodyParam('sourceElementId');
        $targetElementId = (int) $request->getRequiredBodyParam('targetElementId');
        $siteId = (int) $request->getRequiredBodyParam('siteId');
        $status = $request->getRequiredBodyParam('status');
        $score = (float) $request->getRequiredBodyParam('score');
        if (!in_array($status, ['accepted', 'dismissed'], true)) {
            return $this->asJson(['success' => false, 'error' => 'Invalid status']);
        }

        $this->requireSavableSource($sourceElementId, $siteId);
        Plugin::$plugin->links->suggestions->recordInteraction($sourceElementId, $targetElementId, $siteId, $status, $score);
        return $this->asJson(['success' => true]);
    }

    /**
     * Records accept/dismiss interactions for a batch of suggestions.
     *
     * @throws ForbiddenHttpException when the user can't save a source entry
     */
    public function actionBulkUpdate(): Response
    {
        $this->requirePermission(BeaconPermissions::EDIT_LINKS);
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $actions = Http::request()->getRequiredBodyParam('actions');
        if (!is_array($actions)) {
            return $this->asJson(['success' => false, 'error' => 'Invalid actions payload']);
        }

        $updated = 0;
        foreach ($actions as $action) {
            if (!is_array($action) || !isset($action['status'])) {
                continue;
            }
            $status = $action['status'];
            if (!in_array($status, ['accepted', 'dismissed'], true)) {
                continue;
            }
            $sourceElementId = isset($action['sourceElementId']) ? (int) $action['sourceElementId'] : null;
            $targetElementId = isset($action['targetElementId']) ? (int) $action['targetElementId'] : null;
            $siteId = isset($action['siteId']) ? (int) $action['siteId'] : null;
            $score = isset($action['score']) ? (float) $action['score'] : 0.0;
            if ($sourceElementId === null || $targetElementId === null || $siteId === null) {
                continue;
            }
            $this->requireSavableSource($sourceElementId, $siteId);
            Plugin::$plugin->links->suggestions->recordInteraction($sourceElementId, $targetElementId, $siteId, $status, $score);
            $updated++;
        }

        return $this->asJson(['success' => true, 'updated' => $updated]);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Fixed-window per-user rate limiter backed by the Craft cache: up to 30
     * requests per 60-second window per authenticated user.
     */
    private function checkRateLimit(): bool
    {
        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null) {
            // Controller requires login anyway — this branch should never be reached.
            return true;
        }
        $cache = Craft::$app->getCache();
        $key = 'beacon:ratelimit:linkSuggestions:' . $user->id;
        $windowSeconds = 60;
        $maxRequests = 30;

        $now = time();
        $window = $cache->get($key);
        if (!is_array($window) || ($now - (int) ($window['start'] ?? 0)) >= $windowSeconds) {
            // Open a fresh window.
            $cache->set($key, ['start' => $now, 'count' => 1], $windowSeconds);
            return true;
        }

        if ((int) $window['count'] >= $maxRequests) {
            return false;
        }

        // Increment without extending the window: preserve the original start
        // and cap the TTL to the remaining time, so a steady request stream
        // can't keep refreshing a 60s expiry and lock the user out forever.
        $window['count'] = (int) $window['count'] + 1;
        $remaining = max(1, $windowSeconds - ($now - (int) $window['start']));
        $cache->set($key, $window, $remaining);
        return true;
    }

    /**
     * Returns the entry if the current user can view it, or throws.
     *
     * @throws ForbiddenHttpException
     */
    private function requireViewable(int $entryId, int $siteId): Entry
    {
        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null) {
            throw new ForbiddenHttpException();
        }
        /** @var Entry|null $entry */
        $entry = Entry::find()->id($entryId)->siteId($siteId)->status(null)->one();
        if ($entry === null || !Craft::$app->getElements()->canView($entry, $user)) {
            throw new ForbiddenHttpException();
        }
        return $entry;
    }

    /**
     * Verifies the current user can save (edit) the source entry before any
     * suggestion state is mutated.
     *
     * @throws ForbiddenHttpException
     */
    private function requireSavableSource(int $sourceElementId, int $siteId): void
    {
        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null) {
            throw new ForbiddenHttpException();
        }
        /** @var Entry|null $sourceEntry */
        $sourceEntry = Entry::find()->id($sourceElementId)->siteId($siteId)->status(null)->one();
        if ($sourceEntry === null || !Craft::$app->getElements()->canSave($sourceEntry, $user)) {
            throw new ForbiddenHttpException();
        }
    }
}
