<?php

namespace anvildev\beacon\jobs;

use anvildev\beacon\Plugin;
use Craft;
use craft\queue\BaseJob;

/**
 * Async answer-engine visibility run for one site. Scheduled by the cadence
 * (daily/weekly) or pushed by the "Run now" CP action / console command. Kept
 * off the request path because each run makes one LLM call per (prompt × engine)
 * and can take many seconds.
 */
class CheckAiVisibilityJob extends BaseJob
{
    public int $siteId = 0;

    /**
     * @param \craft\queue\QueueInterface $queue
     */
    public function execute($queue): void
    {
        if ($this->siteId <= 0) {
            return;
        }
        if (!Plugin::$plugin->aiVisibility->isActive()) {
            return;
        }
        $summary = Plugin::$plugin->aiVisibility->run($this->siteId);
        Craft::info(
            sprintf(
                'AiVisibility run (site %d): %d evaluated, %d cited, %d failed.',
                $this->siteId,
                $summary['evaluated'],
                $summary['cited'],
                $summary['failed'],
            ),
            'beacon',
        );
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('beacon', 'jobs.aiVisibility.run');
    }
}
