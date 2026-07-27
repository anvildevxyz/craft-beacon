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
     * How many pairs are processed between progress updates.
     *
     * `setProgress()` is a write to the queue table, and the whole point of
     * batching was to stop paying per-element queue round-trips.
     */
    private const PROGRESS_INTERVAL = 10;

    /**
     * @param \craft\queue\QueueInterface $queue
     */
    public function execute($queue): void
    {
        $pairs = $this->targets();
        $total = count($pairs);

        foreach ($pairs as $i => [$elementId, $siteId]) {
            if ($total > 1 && ($i % self::PROGRESS_INTERVAL === 0 || $i === $total - 1)) {
                $this->setProgress($queue, ($i + 1) / $total);
            }

            // One bad element must not cost the rest of the batch its scores.
            // Before batching each pair had its own job, so a failure was
            // isolated by construction; now a throw here would abandon every
            // pair after it with nothing in the CP to say they were skipped.
            try {
                $this->recompute($elementId, $siteId);
            } catch (\Throwable $e) {
                Craft::warning(
                    sprintf(
                        'GEO score recompute failed for element %d on site %d: %s',
                        $elementId,
                        $siteId,
                        $e->getMessage(),
                    ),
                    'beacon',
                );
            }
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

    /**
     * Rescores one pair. Protected so the batch's failure isolation can be
     * exercised without a database.
     */
    protected function recompute(int $elementId, int $siteId): void
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
