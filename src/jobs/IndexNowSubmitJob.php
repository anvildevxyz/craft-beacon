<?php

namespace anvildev\beacon\jobs;

use anvildev\beacon\Plugin;
use Craft;
use craft\queue\BaseJob;

/**
 * Async IndexNow submission. The auto-submit listener pushes one of these
 * per entry save instead of calling {@see \anvildev\beacon\services\IndexNowService::submit()}
 * synchronously — a synchronous HTTP POST would block entry save in
 * bulk-import flows, Commerce variant cascades, and any save where
 * api.indexnow.org is slow/unreachable. The queue handles retries,
 * rate-limiting, and concurrency centrally.
 *
 * Submissions can be batched by passing multiple URLs in a single job
 * (`$urls = [...]`) for chunky workflows like {@see \anvildev\beacon\console\controllers\IndexNowController::actionSection()}.
 *
 * The underlying IndexNowService swallows network errors and logs them, so
 * the job itself rarely throws.
 */
class IndexNowSubmitJob extends BaseJob
{
    public int $siteId = 0;

    /** @var list<string> */
    public array $urls = [];

    /**
     * @param \craft\queue\QueueInterface $queue
     */
    public function execute($queue): void
    {
        if ($this->siteId <= 0 || $this->urls === []) {
            return;
        }
        $site = Craft::$app->getSites()->getSiteById($this->siteId);
        if ($site === null) {
            Craft::warning(
                sprintf('IndexNowSubmitJob: siteId %d not found; dropping %d url(s)', $this->siteId, count($this->urls)),
                'beacon',
            );
            return;
        }

        Plugin::$plugin->indexNow->submit($this->urls, $site);
    }

    protected function defaultDescription(): ?string
    {
        $count = count($this->urls);
        return Craft::t('beacon', 'IndexNow ping for {url}{more}', [
            'url' => $this->urls[0] ?? '?',
            'more' => $count > 1 ? sprintf(' (+%d more)', $count - 1) : '',
        ]);
    }
}
