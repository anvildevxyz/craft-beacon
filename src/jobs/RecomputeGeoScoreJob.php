<?php

namespace anvildev\beacon\jobs;

use anvildev\beacon\Plugin;
use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;

/**
 * Async recompute of the GEO score for a single (entry, site) pair.
 *
 * Enqueued from `Element::EVENT_AFTER_SAVE` when a save in an in-scope
 * section lands. The job is idempotent — `GeoScoreService::compute()`
 * short-circuits on unchanged inputs via `sourceHash`, so re-enqueueing
 * is cheap and bulk re-saves don't cause score churn.
 */
class RecomputeGeoScoreJob extends BaseJob
{
    public int $siteId = 0;
    public int $elementId = 0;

    /**
     * @param \craft\queue\QueueInterface $queue
     */
    public function execute($queue): void
    {
        if ($this->siteId <= 0 || $this->elementId <= 0) {
            return;
        }

        $entry = Entry::find()
            ->id($this->elementId)
            ->siteId($this->siteId)
            ->status(null)
            ->one();
        if (!$entry instanceof Entry) {
            Plugin::$plugin->geoScore->invalidate($this->elementId, $this->siteId);
            return;
        }

        Plugin::$plugin->geoScore->compute($entry, $this->siteId);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('beacon', 'Recomputing GEO score for element {id}', ['id' => $this->elementId]);
    }
}
