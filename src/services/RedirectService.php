<?php

namespace anvildev\beacon\services;

use anvildev\beacon\elements\RedirectElement;
use anvildev\beacon\enums\RedirectQueryStringMode;
use anvildev\beacon\enums\RedirectSource;
use anvildev\beacon\enums\RedirectStatusCode;
use anvildev\beacon\enums\RedirectType;
use anvildev\beacon\events\AfterMatchRedirectEvent;
use anvildev\beacon\events\BeforeMatchRedirectEvent;
use anvildev\beacon\helpers\Db;
use anvildev\beacon\helpers\RedirectStructure;
use anvildev\beacon\helpers\RedirectTargets;
use anvildev\beacon\models\Redirect;
use anvildev\beacon\models\RedirectListFilters;
use anvildev\beacon\records\RedirectRecord;
use Craft;
use craft\db\Query;
use craft\enums\PropagationMethod;
use yii\base\Component;

/**
 * @phpstan-type RedirectDashboardStats array{active: int, stale: int, lastHit: ?string}
 */
class RedirectService extends Component
{
    /**
     * @event BeforeMatchRedirectEvent fires before built-in matching runs;
     *        subscribers may short-circuit by assigning `$event->redirect`
     *        or veto by setting `$event->isHandled = true`.
     */
    public const EVENT_BEFORE_MATCH_REDIRECT = 'beforeMatchRedirect';

    /**
     * @event AfterMatchRedirectEvent fires after a redirect has matched;
     *        subscribers may rewrite the redirect or cancel it by setting
     *        `$event->redirect = null`.
     */
    public const EVENT_AFTER_MATCH_REDIRECT = 'afterMatchRedirect';

    /** @var array<string,string> per-request map of "entryId.siteId" => oldUri */
    private array $oldUriStash = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private RedirectMatcher $matcher, array $config = [])
    {
        parent::__construct($config);
    }

    public function findRedirect(int $siteId, string $uri): ?Redirect
    {
        // Matching orders by sortOrder; make sure any pending (debounced) resync
        // is applied before we read it. No-op on the front-end hot path, where
        // nothing in this request touched the structure.
        $this->flushSortResync();
        if ($this->hasEventHandlers(self::EVENT_BEFORE_MATCH_REDIRECT)) {
            $before = new BeforeMatchRedirectEvent(siteId: $siteId, uri: $uri);
            $this->trigger(self::EVENT_BEFORE_MATCH_REDIRECT, $before);
            if ($before->isHandled) {
                return $this->fireAfterMatch($before->redirect, $uri);
            }
        }
        return $this->fireAfterMatch($this->resolveBuiltin($siteId, $uri), $uri);
    }

    private function fireAfterMatch(?Redirect $redirect, string $uri): ?Redirect
    {
        if (!$this->hasEventHandlers(self::EVENT_AFTER_MATCH_REDIRECT)) {
            return $redirect;
        }
        $event = new AfterMatchRedirectEvent(redirect: $redirect, uri: $uri);
        $this->trigger(self::EVENT_AFTER_MATCH_REDIRECT, $event);
        return $event->redirect;
    }

    private function resolveBuiltin(int $siteId, string $uri): ?Redirect
    {
        [$uriPath, $uriQuery] = $this->matcher->splitPathQuery($uri);

        // Match-mode exact rules carry their full path+query in `sourceUri`.
        if ($uriQuery !== '') {
            $exact = $this->lookupExact($siteId, $uriPath . '?' . $uriQuery);
            if ($exact !== null) {
                return $this->toModel($exact, $siteId, $this->finaliseTarget($exact, '', []));
            }
        }

        $exact = $this->lookupExact($siteId, $uriPath);
        if ($exact !== null) {
            return $this->toModel($exact, $siteId, $this->finaliseTarget($exact, $uriQuery, []));
        }

        foreach ($this->wildcardRulesForSite($siteId) as $rule) {
            $qsMode = RedirectQueryStringMode::tryFrom((string) $rule['queryStringMode'])
                ?? RedirectQueryStringMode::Ignore;
            $match = $this->matcher->matchRule((string) $rule['type'], (string) $rule['sourceUri'], $uri, $qsMode);
            if ($match !== null) {
                return $this->toModel($rule, $siteId, $this->finaliseTarget($rule, $match['query'], $match['captures']));
            }
        }

        return null;
    }

    /**
     * Base query for redirect rows live on a given site: joins the element
     * (enabled, not trashed) and its per-site row. Ordered site-specific
     * (propagation `none`) before all-sites, then by manual `sortOrder`.
     *
     * @return Query<int, array<string, mixed>>
     */
    private function liveQuery(int $siteId): Query
    {
        return (new Query())
            ->from(['r' => '{{%beacon_redirects}}'])
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[r.id]]')
            ->innerJoin(
                ['es' => '{{%elements_sites}}'],
                '[[es.elementId]] = [[r.id]] AND [[es.siteId]] = :siteId',
                [':siteId' => $siteId],
            )
            ->where(['e.enabled' => true, 'es.enabled' => true, 'e.dateDeleted' => null])
            ->orderBy([
                new \yii\db\Expression("[[r.propagationMethod]] = 'none' DESC"),
                'r.sortOrder' => SORT_ASC,
                'r.id' => SORT_ASC,
            ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lookupExact(int $siteId, string $uri): ?array
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->liveQuery($siteId)
            ->select(['r.id', 'r.sourceUri', 'r.targetUri', 'r.statusCode', 'r.type', 'r.queryStringMode'])
            ->andWhere(['r.sourceUri' => $uri, 'r.type' => RedirectType::Exact->value])
            ->one();
        return $row ?: null;
    }

    /** @var array<int, list<array<string, mixed>>> */
    private array $wildcardCache = [];

    /**
     * @return list<array<string, mixed>>
     */
    private function wildcardRulesForSite(int $siteId): array
    {
        return $this->wildcardCache[$siteId] ??= $this->liveQuery($siteId)
            ->select(['r.id', 'r.sourceUri', 'r.targetUri', 'r.statusCode', 'r.type', 'r.queryStringMode'])
            ->andWhere(['r.type' => array_merge(
                [RedirectType::Glob->value, RedirectType::Regex->value],
                array_keys($this->matcher->customTypeLabels()),
            )])
            ->all();
    }

    /**
     * Resolves capture substitution (`$1`, `$2`, …) into the target and, under
     * `preserve` mode, appends the incoming query string when the target has
     * none of its own.
     *
     * @param array<string, mixed> $rule
     * @param array<string,string> $captures
     */
    private function finaliseTarget(array $rule, string $incomingQuery, array $captures): string
    {
        $resolved = $this->resolveTarget((string) $rule['targetUri'], $captures);
        $mode = RedirectQueryStringMode::tryFrom((string) $rule['queryStringMode'])
            ?? RedirectQueryStringMode::Ignore;
        if ($mode !== RedirectQueryStringMode::Preserve || $incomingQuery === '') {
            return $resolved;
        }
        return str_contains($resolved, '?') ? $resolved : $resolved . '?' . $incomingQuery;
    }

    /**
     * @param array<string,string> $captures
     * @throws \RuntimeException when the resolved target still contains CR/LF/NUL characters
     */
    public function resolveTarget(string $targetTemplate, array $captures): string
    {
        $sanitised = array_map(
            static fn(string $v): string => str_replace(["\r", "\n", "\0"], '', $v),
            $captures,
        );
        $resolved = strtr($targetTemplate, $sanitised);
        if (preg_match('/[\r\n\0]/', $resolved) === 1) {
            throw new \RuntimeException('Resolved redirect target contains invalid characters');
        }
        if (RedirectTargets::validateTargetUri($resolved) !== null) {
            throw new \RuntimeException('Resolved redirect target has a disallowed scheme');
        }
        return $resolved;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toModel(array $row, int $siteId, string $resolvedTarget): Redirect
    {
        return new Redirect(
            id: (int) $row['id'],
            siteId: $siteId,
            sourceUri: (string) $row['sourceUri'],
            targetUri: (string) $row['targetUri'],
            statusCode: (int) $row['statusCode'],
            type: (string) $row['type'],
            resolvedTarget: $resolvedTarget,
            queryStringMode: RedirectQueryStringMode::tryFrom((string) $row['queryStringMode'])
                ?? RedirectQueryStringMode::Ignore,
        );
    }

    /**
     * Pending hit counts keyed by redirect id, buffered during the request and
     * written once via {@see self::flushHits()} after the response is on the
     * wire — so a matched 301/302 never waits on its own UPDATE, and a crawl
     * storm hitting the same rule coalesces into a single `hits + N` write.
     *
     * @var array<int,int>
     */
    private array $pendingHits = [];

    /**
     * Buffer a hit for deferred flushing. Used by the 404 response listener so
     * the redirect write stays off the user-facing BEFORE_SEND path.
     */
    public function bufferHit(int $redirectId): void
    {
        $this->pendingHits[$redirectId] ??= 0;
        $this->pendingHits[$redirectId]++;
    }

    /**
     * Flush buffered hits — one coalesced `hits + N` UPDATE per redirect id.
     * Wired to the response AFTER_SEND event alongside the 404/bot log flush.
     */
    public function flushHits(): void
    {
        if ($this->pendingHits === []) {
            return;
        }
        $hits = $this->pendingHits;
        $this->pendingHits = [];
        foreach ($hits as $redirectId => $count) {
            $this->recordHit($redirectId, $count);
        }
    }

    public function recordHit(int $redirectId, int $count = 1): void
    {
        try {
            Craft::$app->getDb()->createCommand()
                ->update('{{%beacon_redirects}}', [
                    'hits' => new \yii\db\Expression('hits + :n', [':n' => $count]),
                    'lastHit' => Db::now(),
                ], ['id' => $redirectId])
                ->execute();
        } catch (\yii\db\Exception $e) {
            Craft::warning('Beacon: failed to record redirect hit: ' . $e->getMessage(), 'beacon');
        }
    }


    public function countForSite(int $siteId): int
    {
        return (int) RedirectElement::find()->siteId($siteId)->status(null)->count();
    }

    /**
     * Aggregate counts for the dashboard redirects card: total rules, how many
     * are stale (never hit and old, or not hit since the cutoff), and the most
     * recent hit timestamp. Counts are global across sites, matching the card's
     * historical behaviour. Returns `lastHit` as a raw SQL datetime string for
     * the caller to format.
     *
     * @return RedirectDashboardStats
     */
    public function dashboardStats(int $staleThresholdDays = 90): array
    {
        $cutoff = Db::cutoff($staleThresholdDays, 'days');

        $active = (int) (new Query())->from('{{%beacon_redirects}}')->count();

        $stale = (int) (new Query())
            ->from('{{%beacon_redirects}}')
            ->where(['or',
                ['and', ['lastHit' => null], ['<', 'dateCreated', $cutoff]],
                ['and', ['not', ['lastHit' => null]], ['<', 'lastHit', $cutoff]],
            ])
            ->count();

        $lastHit = (new Query())
            ->from('{{%beacon_redirects}}')
            ->where(['not', ['lastHit' => null]])
            ->max('[[lastHit]]');

        return [
            'active' => $active,
            'stale' => $stale,
            'lastHit' => is_string($lastHit) && $lastHit !== '' ? $lastHit : null,
        ];
    }

    /**
     * @return list<RedirectElement>
     */
    public function listForSiteFiltered(int $siteId, RedirectListFilters $filters, ?int $staleThresholdDays): array
    {
        $query = RedirectElement::find()->siteId($siteId)->status(null);
        $resolved = \anvildev\beacon\helpers\RedirectListQuery::resolve($filters, $staleThresholdDays);

        foreach ($resolved['where'] as $condition) {
            $query->andWhere($condition);
        }
        if ($resolved['status'] !== null) {
            $query->status($resolved['status']);
        }
        $query->orderBy($resolved['orderBy']);

        /** @var list<RedirectElement> $all */
        $all = $query->all();
        return $all;
    }

    /**
     * @return list<RedirectElement>
     */
    public function recentlyHit(int $limit): array
    {
        /** @var list<RedirectElement> $all */
        $all = RedirectElement::find()
            ->siteId('*')
            ->status(null)
            ->andWhere(['not', ['beacon_redirects.lastHit' => null]])
            ->orderBy(['beacon_redirects.lastHit' => SORT_DESC])
            ->limit($limit)
            ->all();
        return $all;
    }

    public function findById(int $id): ?RedirectElement
    {
        return RedirectElement::find()->id($id)->siteId('*')->status(null)->one();
    }

    /**
     * Returns the next global `sortOrder` value (one greater than the max).
     */
    public function nextSortOrder(?int $siteId = null): int
    {
        // Apply any pending (debounced) resync so the max reflects the latest
        // structure placement within this request.
        $this->flushSortResync();
        $max = RedirectRecord::find()->max('[[sortOrder]]');
        return ((int) ($max ?? -1)) + 1;
    }


    /**
     * The id of the single Craft Structure backing redirect precedence
     * (stored on the settings row), or null if not created yet.
     */
    public function structureId(): ?int
    {
        return RedirectStructure::structureId();
    }

    /**
     * Ensures the redirect Structure exists, recording its id on the settings
     * row and placing any existing redirects into it (in current order). Safe
     * to call repeatedly; returns the structure id.
     */
    public function ensureStructure(): int
    {
        return RedirectStructure::ensureExists();
    }

    /**
     * Whether a structure change has requested a sort-order resync. Set by
     * {@see self::markSortResyncPending()} from the per-element structure
     * listener and cleared by {@see self::flushSortResync()} once per request,
     * so a bulk placement (e.g. CSV import of N redirects) collapses N
     * per-element resyncs into a single full-table pass.
     */
    private bool $sortResyncPending = false;

    /**
     * Mark that the redirect structure changed; the actual (expensive) resync
     * is debounced to once per request via {@see self::flushSortResync()}.
     */
    public function markSortResyncPending(): void
    {
        $this->sortResyncPending = true;
    }

    /**
     * Run the debounced resync if one was requested this request. Wired to the
     * application AFTER_REQUEST event (covers both web reorders and CLI imports).
     */
    public function flushSortResync(): void
    {
        if (!$this->sortResyncPending) {
            return;
        }
        $this->sortResyncPending = false;
        $this->resyncSortOrderFromStructure();
    }

    /**
     * Recomputes each redirect's `sortOrder` from its position in the structure
     * (lft), so the 404 matcher — which orders by `sortOrder` — reflects the
     * editor's drag-to-reorder. Debounced via {@see self::markSortResyncPending()}.
     */
    public function resyncSortOrderFromStructure(): void
    {
        $structureId = $this->structureId();
        if ($structureId === null) {
            return;
        }
        /** @var list<int|string> $elementIds */
        $elementIds = (new Query())
            ->select(['se.elementId'])
            ->from(['se' => '{{%structureelements}}'])
            ->where(['se.structureId' => $structureId])
            ->andWhere(['not', ['se.elementId' => null]])
            ->orderBy(['se.lft' => SORT_ASC])
            ->column();
        Craft::$app->getDb()->transaction(function() use ($elementIds): void {
            foreach ($elementIds as $i => $elementId) {
                RedirectRecord::updateAll(['sortOrder' => $i], ['id' => (int) $elementId]);
            }
        });
        $this->wildcardCache = [];
    }


    /**
     * Syncs the redirect elements owned by a source entry to match
     * `$sourceUris`. Existing rows for URIs no longer listed are deleted;
     * new URIs are inserted as single-site redirect elements.
     *
     * @param list<string> $sourceUris
     * @return array{added:int, removed:int, kept:int}
     */
    public function syncElementSources(int $elementId, int $elementSiteId, string $targetUri, array $sourceUris): array
    {
        $sourceUris = array_values(array_unique(array_filter(
            array_map(static fn(string $u): string => trim($u), $sourceUris),
            static fn(string $u): bool => $u !== '',
        )));

        /** @var list<RedirectElement> $existing */
        $existing = RedirectElement::find()
            ->siteId('*')
            ->status(null)
            ->andWhere([
                'beacon_redirects.elementId' => $elementId,
                'beacon_redirects.elementSiteId' => $elementSiteId,
                'beacon_redirects.source' => RedirectSource::ManualElement->value,
            ])
            ->all();

        $existingBySource = array_column($existing, null, 'sourceUri');

        $added = $kept = $removed = 0;

        foreach ($sourceUris as $uri) {
            if (isset($existingBySource[$uri])) {
                $row = $existingBySource[$uri];
                if ((string) $row->targetUri !== $targetUri) {
                    $row->targetUri = $targetUri;
                    if (!Craft::$app->getElements()->saveElement($row, false)) {
                        Craft::warning(
                            'Beacon: failed to retarget redirect for source "' . $uri . '": '
                            . implode('; ', $row->getFirstErrors()),
                            'beacon',
                        );
                    }
                }
                $kept++;
                unset($existingBySource[$uri]);
                continue;
            }
            if ($this->sourceClaimedOnSite($uri, $elementSiteId)) {
                continue;
            }
            $el = new RedirectElement();
            $el->siteId = $elementSiteId;
            $el->propagationMethod = PropagationMethod::None;
            $el->sourceUri = $uri;
            $el->targetUri = $targetUri;
            $el->statusCode = RedirectStatusCode::MovedPermanently->value;
            $el->type = RedirectType::Exact->value;
            $el->source = RedirectSource::ManualElement->value;
            $el->attachedElementId = $elementId;
            $el->attachedElementSiteId = $elementSiteId;
            $el->sortOrder = $this->nextSortOrder();
            if (Craft::$app->getElements()->saveElement($el, false)) {
                $added++;
            } else {
                Craft::warning(
                    'Beacon: failed to create element-attached redirect for source "' . $uri . '": '
                    . implode('; ', $el->getFirstErrors()),
                    'beacon',
                );
            }
        }

        foreach ($existingBySource as $row) {
            Craft::$app->getElements()->deleteElement($row);
            $removed++;
        }

        return ['added' => $added, 'removed' => $removed, 'kept' => $kept];
    }

    /**
     * @return list<string>
     */
    public function sourcesForElement(int $elementId, int $elementSiteId): array
    {
        $col = (new Query())
            ->select(['sourceUri'])
            ->from('{{%beacon_redirects}}')
            ->where([
                'elementId' => $elementId,
                'elementSiteId' => $elementSiteId,
                'source' => RedirectSource::ManualElement->value,
            ])
            ->orderBy(['sourceUri' => SORT_ASC])
            ->column();
        return array_values(array_map(static fn($v): string => (string) $v, $col));
    }

    /**
     * Updates the target on all element-attached redirects for the given
     * source entry (e.g. when the entry's URI changes).
     */
    public function retargetElementSources(int $elementId, int $elementSiteId, string $newTarget): int
    {
        return (int) Craft::$app->getDb()->createCommand()
            ->update('{{%beacon_redirects}}', ['targetUri' => $newTarget], [
                'elementId' => $elementId,
                'elementSiteId' => $elementSiteId,
                'source' => RedirectSource::ManualElement->value,
            ])
            ->execute();
    }

    public function createAutoRedirect(int $siteId, string $source, string $target): void
    {
        if ($this->sourceClaimedOnSite($source, $siteId)) {
            return;
        }
        $el = new RedirectElement();
        $el->siteId = $siteId;
        $el->propagationMethod = PropagationMethod::None;
        $el->sourceUri = $source;
        $el->targetUri = $target;
        $el->statusCode = RedirectStatusCode::MovedPermanently->value;
        $el->type = RedirectType::Exact->value;
        $el->source = RedirectSource::AutoSlug->value;
        $el->sortOrder = $this->nextSortOrder();
        if (!Craft::$app->getElements()->saveElement($el, false)) {
            Craft::warning(
                'Beacon: failed to create auto-redirect "' . $source . '" -> "' . $target . '": '
                . implode('; ', $el->getFirstErrors()),
                'beacon',
            );
        }
    }

    /**
     * Whether any redirect element with this source is already live on the site.
     */
    public function sourceClaimedOnSite(string $sourceUri, int $siteId): bool
    {
        return RedirectElement::find()
            ->siteId($siteId)
            ->status(null)
            ->andWhere(['beacon_redirects.sourceUri' => $sourceUri])
            ->exists();
    }


    /**
     * @return list<array{id:int, sourceUri:string, lastHit:?string, dateCreated:string, hits:int}>
     */
    public function audit(?int $siteId = null, int $thresholdDays = 90): array
    {
        $cutoff = Db::cutoff($thresholdDays, 'days');
        $query = (new Query())
            ->select(['r.id', 'r.sourceUri', 'r.hits', 'r.lastHit', 'e.dateCreated'])
            ->from(['r' => '{{%beacon_redirects}}'])
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[r.id]]')
            ->where(['e.dateDeleted' => null])
            ->andWhere(['or',
                ['<', 'r.lastHit', $cutoff],
                ['and', ['r.lastHit' => null], ['<', 'e.dateCreated', $cutoff]],
            ]);
        if ($siteId !== null) {
            $query->innerJoin(['es' => '{{%elements_sites}}'], '[[es.elementId]] = [[r.id]] AND [[es.siteId]] = :siteId', [':siteId' => $siteId]);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $query->all();
        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'sourceUri' => (string) $r['sourceUri'],
            'lastHit' => $r['lastHit'] !== null ? (string) $r['lastHit'] : null,
            'dateCreated' => (string) $r['dateCreated'],
            'hits' => (int) $r['hits'],
        ], $rows);
    }

    /**
     * Finds redirect chains (a redirect whose target is itself another
     * redirect's source — A→B→C) and loops (a cycle — A→A or A→B→A) among the
     * live exact redirects on a site. Both hurt SEO and waste hops; the audit
     * surfaces them so an author can collapse the chain or break the loop.
     *
     * Only exact-type rules are graph-resolved (glob/regex sources are patterns,
     * not concrete addressable nodes); targets are compared on their path,
     * ignoring scheme/host/query.
     *
     * @return list<array{id:int, sourceUri:string, kind:string, hops:list<string>}>
     */
    public function findChainsAndLoops(int $siteId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->liveQuery($siteId)
            ->select(['r.id', 'r.sourceUri', 'r.targetUri'])
            ->andWhere(['r.type' => RedirectType::Exact->value])
            ->all();

        $edges = [];
        foreach ($rows as $row) {
            $source = $this->normalizeRedirectPath((string) $row['sourceUri']);
            // First rule wins per source (sources are unique per site anyway).
            $edges[$source] ??= ['id' => (int) $row['id'], 'target' => $this->normalizeRedirectPath((string) $row['targetUri'])];
        }

        return self::detectGraphIssues($edges);
    }

    /**
     * Pure graph analysis over source→target edges, extracted so it can be unit
     * tested without a database. Walks each edge's target through the map; a
     * revisited node is a loop, a target that is itself a source is a chain.
     * Each cycle is reported once.
     *
     * @param array<string, array{id:int, target:string}> $edges normalized-path keyed
     * @return list<array{id:int, sourceUri:string, kind:string, hops:list<string>}>
     */
    public static function detectGraphIssues(array $edges): array
    {
        $issues = [];
        $loopSeen = [];
        foreach ($edges as $source => $edge) {
            $hops = [(string) $source];
            $visited = [(string) $source => true];
            $current = $edge['target'];
            $loop = false;
            $depth = 0;
            while (isset($edges[$current]) && $depth < 50) {
                $hops[] = $current;
                if (isset($visited[$current])) {
                    $loop = true;
                    break;
                }
                $visited[$current] = true;
                $current = $edges[$current]['target'];
                $depth++;
            }
            if ($loop) {
                if (isset($loopSeen[$source])) {
                    continue;
                }
                foreach ($hops as $hop) {
                    $loopSeen[$hop] = true;
                }
                $issues[] = ['id' => $edge['id'], 'sourceUri' => (string) $source, 'kind' => 'loop', 'hops' => $hops];
            } elseif (count($hops) >= 2) {
                // Append the terminal destination (not itself a source) so the
                // chain reads end-to-end: A → B → C.
                $hops[] = $current;
                $issues[] = ['id' => $edge['id'], 'sourceUri' => (string) $source, 'kind' => 'chain', 'hops' => $hops];
            }
        }
        return $issues;
    }

    /**
     * Reduce a source or target URI to a comparable path: strip scheme/host of
     * absolute targets, drop query/fragment, and force a single leading slash so
     * a target like `https://site/b?x=1` links to the exact source `/b`.
     */
    private function normalizeRedirectPath(string $uri): string
    {
        $uri = trim($uri);
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $uri;
        }
        $path = '/' . ltrim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function stashOldUri(int $entryId, string $uri, int $siteId): void
    {
        $this->oldUriStash[$entryId . '.' . $siteId] = $uri;
    }

    public function popOldUri(int $entryId, int $siteId): ?string
    {
        $key = $entryId . '.' . $siteId;
        $uri = $this->oldUriStash[$key] ?? null;
        unset($this->oldUriStash[$key]);
        return $uri;
    }
}
