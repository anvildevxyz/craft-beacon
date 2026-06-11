<?php

namespace anvildev\beacon\models;

use anvildev\beacon\enums\GeoScorePillar;

/**
 * Per-pillar slice of the composite GEO score. `score` is 0–10; `band` is
 * a coarse label that survives translation; `notes` is the actionable
 * feedback string list shown in the GEO score drill-down.
 *
 * `debug` carries diagnostic data for the team — never rendered in UI.
 *
 * @phpstan-type GeoPillarScoreArray array{
 *     pillar: string,
 *     score: int,
 *     band: string,
 *     notes: list<string>,
 *     debug?: array<string, scalar|null|array<int|string, scalar|null|array<string, scalar|null>>>,
 * }
 */
final class GeoPillarScore
{
    public const BAND_TOP = 'top';
    public const BAND_GOOD = 'good';
    public const BAND_LOW = 'low';
    public const BAND_STALE = 'stale';

    /**
     * @param list<string> $notes
     * @param array<string, scalar|null|array<int|string, scalar|null|array<string, scalar|null>>> $debug
     */
    public function __construct(
        public readonly GeoScorePillar|string $pillar,
        public readonly int $score,
        public readonly string $band,
        public readonly array $notes = [],
        public readonly array $debug = [],
    ) {
    }

    /**
     * Stable string handle, whether built-in enum case or custom string handle.
     */
    public function pillarHandle(): string
    {
        return $this->pillar instanceof GeoScorePillar ? $this->pillar->value : $this->pillar;
    }

    /**
     * Human-readable label: translated enum label for built-ins, raw handle for
     * a third-party custom pillar.
     */
    public function label(): string
    {
        return $this->pillar instanceof GeoScorePillar ? $this->pillar->label() : $this->pillar;
    }

    /**
     * Map a 0–10 score to a band label. Top: 8–10. Good: 5–7. Low: 2–4.
     * Stale: 0–1.
     */
    public static function bandFor(int $score): string
    {
        return match (true) {
            $score >= 8 => self::BAND_TOP,
            $score >= 5 => self::BAND_GOOD,
            $score >= 2 => self::BAND_LOW,
            default => self::BAND_STALE,
        };
    }

    /**
     * Convert a 0–1 ratio to a clamped 0–10 pillar score — the shared
     * contract every pillar computes against.
     */
    public static function scoreFromRatio(float $ratio): int
    {
        return self::clampScore((int) round($ratio * 10));
    }

    /**
     * Clamp an arbitrary integer into the 0–10 pillar score range.
     */
    public static function clampScore(int $score): int
    {
        return max(0, min(10, $score));
    }

    /**
     * @return GeoPillarScoreArray
     */
    public function toArray(): array
    {
        $result = [
            'pillar' => $this->pillarHandle(),
            'score' => $this->score,
            'band' => $this->band,
            'notes' => $this->notes,
        ];
        if ($this->debug !== []) {
            $result['debug'] = $this->debug;
        }
        return $result;
    }
}
