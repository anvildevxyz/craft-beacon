<?php

namespace anvildev\beacon\services;

use anvildev\beacon\helpers\Db;
use Craft;
use craft\db\Query;
use yii\base\Component;

class BotLogService extends Component
{
    /** @var list<array{siteId:int,botName:string,path:string}> */
    private array $pending = [];

    /** @param array<string, mixed> $config */
    public function __construct(private BotRegistry $registry, array $config = [])
    {
        parent::__construct($config);
    }

    /**
     * Buffers a bot hit if the user agent matches a known bot. The actual DB
     * write is deferred to {@see flush()} on EVENT_AFTER_SEND, so the visitor
     * (typically the very crawler this plugin targets) never waits on the
     * INSERT — it runs after the response is on the wire. Returns true if the
     * hit was buffered, false if the UA did not match a bot.
     */
    public function logIfBot(string $userAgent, string $path, int $siteId): bool
    {
        $bot = $this->registry->match($userAgent);
        if ($bot === null) {
            return false;
        }

        $this->pending[] = [
            'siteId' => $siteId,
            'botName' => $bot->name,
            'path' => $this->normalizePath($path),
        ];
        return true;
    }

    /**
     * Writes the buffered bot hits to the DB. Idempotent: clears the buffer
     * before writing so a re-entrant call cannot double-insert, and a failed
     * row is logged rather than propagated to the (already-sent) response.
     */
    public function flush(): void
    {
        if ($this->pending === []) {
            return;
        }
        $rows = $this->pending;
        $this->pending = [];
        $now = Db::now();
        $db = Craft::$app->getDb();

        foreach ($rows as $row) {
            try {
                $db->createCommand()->insert('{{%beacon_bot_log}}', [
                    'siteId' => $row['siteId'],
                    'botName' => $row['botName'],
                    'path' => $row['path'],
                    'hitAt' => $now,
                ])->execute();
            } catch (\yii\db\Exception $e) {
                Craft::warning('Beacon: bot log insert failed: ' . $e->getMessage(), 'beacon');
            }
        }
    }

    public function gc(int $retentionDays): int
    {
        return (int) Craft::$app->getDb()->createCommand()
            ->delete('{{%beacon_bot_log}}', ['<', 'hitAt', $this->retentionCutoff($retentionDays)])
            ->execute();
    }

    /**
     * Recent bot crawl rows for the dashboard activity feed, newest first.
     *
     * @return list<array{botName: string, path: string, hitAt: string}>
     */
    public function recentActivity(int $limit, int $withinDays): array
    {
        /** @var list<array<string, string|null>> $rows */
        $rows = (new Query())
            ->select(['botName', 'path', 'hitAt'])
            ->from('{{%beacon_bot_log}}')
            ->where(['>=', 'hitAt', Db::cutoff($withinDays, 'days')])
            ->orderBy(['hitAt' => SORT_DESC])
            ->limit($limit)
            ->all();

        return array_map(static fn(array $r): array => [
            'botName' => (string) $r['botName'],
            'path' => (string) ($r['path'] ?? ''),
            'hitAt' => (string) $r['hitAt'],
        ], $rows);
    }

    public function normalizePath(string $path): string
    {
        return substr($path, 0, 255);
    }

    public function retentionCutoff(int $retentionDays): string
    {
        return Db::cutoff($retentionDays, 'days');
    }
}
