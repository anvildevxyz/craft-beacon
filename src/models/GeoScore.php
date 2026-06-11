<?php

namespace anvildev\beacon\models;

use anvildev\beacon\enums\GeoScorePillar;
use DateTimeImmutable;

/**
 * @phpstan-import-type GeoPillarScoreArray from \anvildev\beacon\models\GeoPillarScore
 * Composite GEO score for an (element, site) pair.
 *
 * `score` is the 0–100 weighted composite; `pillars` is a map keyed by
 * pillar handle so the SEO field chip and drill-down can render specific
 * slices without iterating. `weakestPillar()` returns the pillar handle
 * with the lowest score (ties broken by enum order — deterministic).
 *
 * @phpstan-type GeoScoreArray array{
 *     score: int,
 *     pillars: array<string, GeoPillarScoreArray>,
 *     computedAt: string,
 * }
 */
final class GeoScore
{
    /**
     * @param array<string, GeoPillarScore> $pillars Keyed by pillar handle.
     */
    public function __construct(
        public readonly int $score,
        public readonly array $pillars,
        public readonly DateTimeImmutable $computedAt,
    ) {
    }

    public function weakestPillar(): ?GeoScorePillar
    {
        $weakest = null;
        $weakestScore = PHP_INT_MAX;
        foreach (GeoScorePillar::cases() as $case) {
            $pillar = $this->pillars[$case->value] ?? null;
            if ($pillar === null) {
                continue;
            }
            if ($pillar->score < $weakestScore) {
                $weakest = $case;
                $weakestScore = $pillar->score;
            }
        }
        return $weakest;
    }

    /**
     * @return GeoScoreArray
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'pillars' => array_map(fn(GeoPillarScore $p) => $p->toArray(), $this->pillars),
            'computedAt' => $this->computedAt->format(\DATE_ATOM),
        ];
    }
}
