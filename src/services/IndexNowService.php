<?php

namespace anvildev\beacon\services;

use anvildev\beacon\helpers\IndexNow as IndexNowHelper;
use anvildev\beacon\jobs\IndexNowSubmitJob;
use anvildev\beacon\Plugin;
use anvildev\beacon\records\IndexNowSubmissionRecord;
use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\models\Site;
use GuzzleHttp\Exception\GuzzleException;
use yii\base\Component;

/**
 * Submits URLs to the IndexNow protocol — a single POST that fans out to
 * Bing, Yandex, Naver, and Seznam (the IndexNow consortium). One request,
 * multiple search engines, no per-engine API keys.
 *
 * Wire-up:
 *   - the per-site API key lives on `WebmasterSettings::$indexNowKey`
 *   - the global `Settings::$indexNowEnabled` toggle gates auto-submission
 *   - on `Element::EVENT_AFTER_SAVE` for live entries, the Beacon Plugin
 *     listener calls {@see self::submitForEntry()} (registered in Plugin::init)
 *   - the CP exposes a `/{key}.txt` ownership-proof endpoint
 *     (see {@see \anvildev\beacon\controllers\IndexNowKeyController})
 *
 * Failures are logged at warning level and never propagated — IndexNow is
 * best-effort and search engines tolerate occasional misses, so a network
 * blip should never break an entry save.
 */
final class IndexNowService extends Component
{
    /**
     * IndexNow's generic API endpoint. Bing/Yandex/Naver/Seznam all forward
     * to this host; per-engine endpoints exist but the generic one is the
     * canonical multi-engine entry point.
     */
    public const ENDPOINT = 'https://api.indexnow.org/indexnow';

    /**
     * Submit a single URL on behalf of $site. No-op when IndexNow is disabled
     * globally or the site has no key configured.
     */
    public function submitUrl(string $url, Site $site): bool
    {
        return $this->submit([$url], $site);
    }

    /**
     * Submit a batch (max 10,000 URLs per request per IndexNow spec; callers
     * should chunk for larger batches).
     *
     * @param list<string> $urls
     */
    public function submit(array $urls, Site $site): bool
    {
        $urls = IndexNowHelper::normalizeUrls($urls);
        if ($urls === []) {
            return false;
        }

        $plugin = Plugin::$plugin;
        if ($plugin === null || !$plugin->settings->get()->indexNowEnabled) {
            return false;
        }

        $key = $plugin->siteSettings->getWebmaster($site->id)->indexNowKey;
        if (!is_string($key) || trim($key) === '') {
            return false;
        }

        $baseUrl = (string) $site->getBaseUrl();
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            Craft::warning("IndexNow: site {$site->id} has no resolvable host; skipping submit", 'beacon');
            return false;
        }

        $payload = IndexNowHelper::buildPayload($host, $key, $baseUrl, $urls);

        try {
            $response = Craft::createGuzzleClient(['timeout' => 5])->post(self::ENDPOINT, [
                'json' => $payload,
                'http_errors' => false,
            ]);
            $status = $response->getStatusCode();
            if (IndexNowHelper::isSuccessStatus($status)) {
                Craft::info(sprintf(
                    'IndexNow: submitted %d url(s) for site %d (status %d)',
                    count($urls),
                    $site->id,
                    $status,
                ), 'beacon');
                $this->recordSubmission($site, $urls, $status, true, null);
                return true;
            }
            // Read body once — PSR-7 streams aren't reliably re-readable after the first cast.
            $body = (string) $response->getBody();
            Craft::warning(sprintf(
                'IndexNow: %d url(s) rejected for site %d — status %d, body=%s',
                count($urls),
                $site->id,
                $status,
                substr($body, 0, 200),
            ), 'beacon');
            $this->recordSubmission($site, $urls, $status, false, IndexNowHelper::rejectionNote($body));
            return false;
        } catch (GuzzleException $e) {
            Craft::warning('IndexNow submit failed: ' . $e->getMessage(), 'beacon');
            $this->recordSubmission($site, $urls, null, false, 'guzzle: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Append a row to `beacon_indexnow_submissions`. The dashboard widget
     * reads this so operators can verify activity even when Craft's prod log
     * target suppresses INFO. Errors here are swallowed — telemetry must
     * never break the primary submit path.
     *
     * @param list<string> $urls
     */
    private function recordSubmission(Site $site, array $urls, ?int $statusCode, bool $succeeded, ?string $note): void
    {
        try {
            $record = new IndexNowSubmissionRecord();
            $record->siteId = $site->id;
            $record->urlCount = count($urls);
            $record->firstUrl = $urls[0] ?? null;
            $record->statusCode = $statusCode;
            $record->succeeded = $succeeded;
            $record->note = $note !== null ? substr($note, 0, 255) : null;
            $record->submittedAt = Db::prepareDateForDb(new \DateTime());
            $record->save(false);
        } catch (\Throwable $e) {
            Craft::warning('IndexNow submission log write failed: ' . $e->getMessage(), 'beacon');
        }
    }

    /**
     * Returns the most recent submission rows for the widget. Bounded by
     * `$limit` (default 20).
     *
     * @return list<array{siteId:int, urlCount:int, firstUrl:?string, statusCode:?int, succeeded:bool, note:?string, submittedAt:string}>
     */
    public function recentSubmissions(int $limit = 20, ?int $siteId = null): array
    {
        $query = IndexNowSubmissionRecord::find()
            ->orderBy(['submittedAt' => SORT_DESC])
            ->limit($limit);
        if ($siteId !== null) {
            $query->where(['siteId' => $siteId]);
        }
        /** @var list<IndexNowSubmissionRecord> $records */
        $records = $query->all();
        return array_map(static fn(IndexNowSubmissionRecord $r): array => [
            'siteId' => (int) $r->siteId,
            'urlCount' => (int) $r->urlCount,
            'firstUrl' => $r->firstUrl,
            'statusCode' => $r->statusCode !== null ? (int) $r->statusCode : null,
            'succeeded' => (bool) $r->succeeded,
            'note' => $r->note,
            'submittedAt' => (string) $r->submittedAt,
        ], $records);
    }

    /**
     * Counts grouped by succeeded for the last $hours hours, optionally
     * narrowed to one site.
     *
     * @return array{ok:int, failed:int, total:int}
     */
    public function recentCounts(int $hours = 168, ?int $siteId = null): array
    {
        $since = (new \DateTime())->modify("-{$hours} hours")->format('Y-m-d H:i:s');

        // Go through Craft's query builder so the boolean `succeeded` column is
        // bound per-driver (`['succeeded' => true]`). A raw `succeeded = 1`
        // comparison plans fine on MySQL (tinyint) but throws
        // "operator does not exist: boolean = integer" on PostgreSQL.
        $base = (new Query())
            ->from('{{%beacon_indexnow_submissions}}')
            ->where(['>=', 'submittedAt', $since]);
        if ($siteId !== null) {
            $base->andWhere(['siteId' => $siteId]);
        }

        // Clone before narrowing so the `succeeded` filter doesn't leak into
        // the total count.
        $total = (int) (clone $base)->count();
        $ok = (int) (clone $base)->andWhere(['succeeded' => true])->count();

        return ['ok' => $ok, 'failed' => $total - $ok, 'total' => $total];
    }

    /**
     * Synchronous submit for an entry's canonical URL. The save listener
     * should use {@see self::queueForEntry()} so the HTTP call doesn't
     * block the save.
     */
    public function submitForEntry(Entry $entry): bool
    {
        if (!$this->isEntryEligible($entry)) {
            return false;
        }
        $url = $entry->getUrl();
        $site = Craft::$app->getSites()->getSiteById((int) $entry->siteId);
        if (!is_string($url) || $url === '' || $site === null) {
            return false;
        }
        return $this->submitUrl($url, $site);
    }

    /**
     * Async counterpart of {@see self::submitForEntry()} — pushes an
     * {@see \anvildev\beacon\jobs\IndexNowSubmitJob} to the queue.
     *
     * Returns true if a job was pushed, false if the entry isn't eligible
     * (draft / disabled / no URL / no site) — a soft no-op for callers.
     */
    public function queueForEntry(Entry $entry): bool
    {
        if (!$this->isEntryEligible($entry)) {
            return false;
        }
        $url = $entry->getUrl();
        if (!is_string($url) || $url === '') {
            return false;
        }
        $siteId = (int) $entry->siteId;
        if ($siteId <= 0) {
            return false;
        }

        if (!Plugin::$plugin->settings->get()->indexNowEnabled) {
            return false;
        }
        Craft::$app->getQueue()->push(new IndexNowSubmitJob([
            'siteId' => $siteId,
            'urls' => [$url],
        ]));
        return true;
    }

    private function isEntryEligible(Entry $entry): bool
    {
        return $entry->id
            && $entry->getEnabledForSite()
            && $entry->getStatus() === Entry::STATUS_LIVE
            && !$entry->getIsDraft()
            && !$entry->getIsRevision();
    }
}
