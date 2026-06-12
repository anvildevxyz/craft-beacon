<?php

namespace anvildev\beacon\services\scoring;

use anvildev\beacon\enums\GeoScorePillar;
use anvildev\beacon\models\GeoPillarScore;
use Craft;
use DateTimeImmutable;

/**
 * Bands by element age relative to `dateUpdated`:
 *
 *   <30d  → 10/10  (top)
 *   <180d →  7/10  (good)
 *   <2y   →  4/10  (low)
 *   stale →  1/10  (stale)
 *
 * Grounded in the GEO-16 framework's metadata/freshness correlation.
 */
final class FreshnessBandingPillar implements PillarComputerInterface
{
    private const DAYS_FRESH = 30;
    private const DAYS_RECENT = 180;
    private const DAYS_AGING = 730;

    public function __construct(private readonly ?DateTimeImmutable $now = null)
    {
    }

    public function pillar(): GeoScorePillar
    {
        return GeoScorePillar::FreshnessBanding;
    }

    public function compute(PillarContext $ctx): GeoPillarScore
    {
        $updated = $ctx->element->dateUpdated;
        if (!$updated instanceof \DateTimeInterface) {
            return new GeoPillarScore(
                pillar: $this->pillar(),
                score: 1,
                band: GeoPillarScore::BAND_STALE,
                notes: [Craft::t('beacon', 'geo.pillar.freshness.entry.has.no.last.updated')],
            );
        }

        $now = $this->now ?? new DateTimeImmutable();
        $ageDays = (int) max(0, floor(($now->getTimestamp() - $updated->getTimestamp()) / 86400));

        [$score, $band, $note] = match (true) {
            $ageDays < self::DAYS_FRESH => [
                10,
                GeoPillarScore::BAND_TOP,
                Craft::t('beacon', 'geo.pillar.freshness.updated.within.last.30.days'),
            ],
            $ageDays < self::DAYS_RECENT => [
                7,
                GeoPillarScore::BAND_GOOD,
                Craft::t(
                    'beacon',
                    'geo.pillar.freshness.updated.days.republish',
                    ['days' => $ageDays],
                ),
            ],
            $ageDays < self::DAYS_AGING => [
                4,
                GeoPillarScore::BAND_LOW,
                Craft::t(
                    'beacon',
                    'geo.pillar.freshness.updated.days.downweight',
                    ['days' => $ageDays],
                ),
            ],
            default => [
                1,
                GeoPillarScore::BAND_STALE,
                Craft::t(
                    'beacon',
                    'geo.pillar.freshness.updated.days.refresh',
                    ['days' => $ageDays],
                ),
            ],
        };

        return new GeoPillarScore(
            pillar: $this->pillar(),
            score: $score,
            band: $band,
            notes: [$note],
            debug: ['ageDays' => $ageDays],
        );
    }
}
