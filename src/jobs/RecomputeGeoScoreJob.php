<?php

namespace anvildev\beacon\jobs;

use anvildev\beacon\Plugin;
use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;

/**
 * Async recompute of the GEO score for a batch of (entry, site) pairs.
 *
 * Enqueued from `Element::EVENT_AFTER_SAVE` when a save in an in-scope
 * section lands, via `GeoScoreService::queueRecompute()` which coalesces and
 * chunks the targets. The job is idempotent — `GeoScoreService::compute()`
 * short-circuits on unchanged inputs via `sourceHash`, so re-enqueueing
 * is cheap and bulk re-saves don't cause score churn.
 */
class RecomputeGeoScoreJob extends BaseJob
{
    /**
     * Single-pair fields, retained so jobs serialised by an earlier version and
     * still sitting in the queue keep executing after an upgrade. New jobs use
     * {@see self::$pairs}.
     */
    public int $siteId = 0;
    public int $elementId = 0;

    /**
     * Batched targets as `[elementId, siteId]` tuples.
     *
     * @var list<array{int, int}>
     */
    public array $pairs = [];

    /**
     * @param \craft\queue\QueueInterface $queue
     */
    public function execute($queue): void
    {
        $pairs = $this->targets();
        $total = count($pairs);

        foreach ($pairs as $i => [$elementId, $siteId]) {
            $this->setProgress($queue, $total > 0 ? ($i + 1) / $total : 1);
            $this->recompute($elementId, $siteId);
        }
    }

    /**
     * @return list<array{int, int}>
     */
    private function targets(): array
    {
        if ($this->pairs !== []) {
            return array_values($this->pairs);
        }

        if ($this->elementId > 0 && $this->siteId > 0) {
            return [[$this->elementId, $this->siteId]];
        }

        return [];
    }

    private function recompute(int $elementId, int $siteId): void
    {
        if ($elementId <= 0 || $siteId <= 0) {
            return;
        }

        $entry = Entry::find()
            ->id($elementId)
            ->siteId($siteId)
            ->status(null)
            ->one();
        if (!$entry instanceof Entry) {
            Plugin::$plugin->geoScore->invalidate($elementId, $siteId);
            return;
        }

        Plugin::$plugin->geoScore->compute($entry, $siteId);
    }

    protected function defaultDescription(): ?string
    {
        $count = count($this->targets());

        if ($count === 1) {
            [$elementId] = $this->targets()[0];
            return Craft::t('beacon', 'jobs.geoScore.recomputing.geo.score.element', ['id' => $elementId]);
        }

        return Craft::t('beacon', 'jobs.geoScore.recomputing.geo.scores', ['count' => $count]);
    }
}
