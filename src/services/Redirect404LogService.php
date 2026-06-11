<?php

namespace anvildev\beacon\services;

use anvildev\beacon\helpers\Db;
use anvildev\beacon\helpers\Redirect404LogQuery;
use anvildev\beacon\records\Redirect404LogRecord;
use Craft;
use craft\db\Query;
use craft\helpers\StringHelper;
use yii\base\Component;
use yii\db\Expression;

/**
 * Captures unhandled 404s as (siteId, uri) rows so the CP can surface the
 * top offenders + propose redirect targets. Bots (anything the BotRegistry
 * matches) are skipped — a 404 storm from Googlebot crawling stale links
 * is noise, not a fix-the-content signal.
 *
 * Writes are buffered per request and flushed once on EVENT_AFTER_REQUEST,
 * so a single page that 404s 20 chained assets (CSS/JS hot-reload misses)
 * costs one DB round-trip, not 20.
 *
 * @phpstan-import-type Redirect404LogRow from \anvildev\beacon\types\ArrayShapes
 */
class Redirect404LogService extends Component
{
    /** @var array<string, array{siteId:int,uri:string,referer:?string,count:int}> */
    private array $pendingUpserts = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private BotRegistry $bots, array $config = [])
    {
        parent::__construct($config);
    }

    /**
     * Queues a 404 for upsert. Safe to call many times per request; rows are
     * deduplicated by (siteId, uri) before the flush. Returns true if the URI
     * was queued, false if it was filtered out (bot / oversize / empty).
     */
    public function record(int $siteId, string $uri, string $userAgent = '', ?string $referer = null): bool
    {
        $uri = trim($uri);
        if ($uri === '' || strlen($uri) > 500) {
            return false;
        }
        if ($userAgent !== '' && $this->bots->match($userAgent) !== null) {
            return false;
        }

        $key = $siteId . "\0" . $uri;
        if (isset($this->pendingUpserts[$key])) {
            $this->pendingUpserts[$key]['count']++;
            return true;
        }
        $this->pendingUpserts[$key] = [
            'siteId' => $siteId,
            'uri' => $uri,
            'referer' => $referer !== null ? substr($referer, 0, 500) : null,
            'count' => 1,
        ];
        return true;
    }

    /**
     * Flushes the per-request buffer to the DB. Idempotent: clears the
     * buffer on success, leaves it on failure so a retry could rerun.
     */
    public function flush(): void
    {
        if ($this->pendingUpserts === []) {
            return;
        }
        $db = Craft::$app->getDb();
        $now = Db::now();

        foreach ($this->pendingUpserts as $row) {
            try {
                $db->createCommand()->upsert(
                    '{{%beacon_redirect_404_log}}',
                    [
                        'siteId' => $row['siteId'],
                        'uri' => $row['uri'],
                        'hits' => $row['count'],
                        'firstSeen' => $now,
                        'lastSeen' => $now,
                        'referer' => $row['referer'],
                        'handled' => false,
                        'dateCreated' => $now,
                        'dateUpdated' => $now,
                        'uid' => StringHelper::UUID(),
                    ],
                    [
                        // Table-qualified: a bare `hits` inside Postgres'
                        // `ON CONFLICT … DO UPDATE` is ambiguous (SQLSTATE 42702)
                        // between the target row and the EXCLUDED pseudo-row.
                        // MySQL accepts the qualified form in ON DUPLICATE KEY too.
                        'hits' => new Expression('{{%beacon_redirect_404_log}}.[[hits]] + ' . (int) $row['count']),
                        'lastSeen' => $now,
                        'handled' => false,
                        'referer' => $row['referer'],
                        'dateUpdated' => $now,
                    ],
                )->execute();
            } catch (\yii\db\Exception $e) {
                Craft::warning('Beacon: 404 log upsert failed: ' . $e->getMessage(), 'beacon');
            }
        }
        $this->pendingUpserts = [];
    }

    /**
     * @return list<Redirect404LogRow>
     */
    public function topUnhandled(int $siteId, int $limit = 50): array
    {
        return Redirect404LogQuery::topUnhandled($siteId, $limit);
    }

    public function countUnhandledSince(int $siteId, string $since): int
    {
        return (int) (new Query())
            ->from('{{%beacon_redirect_404_log}}')
            ->where(['siteId' => $siteId, 'handled' => false])
            ->andWhere(['>=', 'lastSeen', $since])
            ->count();
    }

    public function findById(int $id): ?Redirect404LogRecord
    {
        return Redirect404LogRecord::findOne($id);
    }

    public function markHandled(int $id, int $siteId): bool
    {
        return (int) Redirect404LogRecord::updateAll(
            ['handled' => true, 'dateUpdated' => Db::now()],
            ['id' => $id, 'siteId' => $siteId],
        ) > 0;
    }

    /**
     * @param list<int> $ids
     */
    public function bulkMarkHandled(array $ids, int $siteId): int
    {
        if ($ids === []) {
            return 0;
        }
        return (int) Redirect404LogRecord::updateAll(
            ['handled' => true, 'dateUpdated' => Db::now()],
            ['and', ['siteId' => $siteId], ['id' => $ids]],
        );
    }

    /**
     * Removes rows whose lastSeen is older than `$retentionDays` days, OR
     * whose `handled` flag is set and they're older than 7 days (handled
     * rows are housekeeping junk, prune sooner). Returns rows deleted.
     */
    public function prune(int $retentionDays): int
    {
        $cutoff = Db::cutoff($retentionDays, 'days');
        $handledCutoff = Db::cutoff(7, 'days');
        return (int) Craft::$app->getDb()->createCommand()
            ->delete('{{%beacon_redirect_404_log}}', [
                'or',
                ['<', 'lastSeen', $cutoff],
                ['and', ['handled' => true], ['<', 'dateUpdated', $handledCutoff]],
            ])
            ->execute();
    }
}
