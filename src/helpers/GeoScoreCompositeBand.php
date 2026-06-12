<?php

namespace anvildev\beacon\helpers;

use Craft;

/**
 * Maps a 0–100 composite GEO score to the same five bands used by the
 * dashboard widget histogram (stale / low / fair / good / top).
 */
final class GeoScoreCompositeBand
{
    public const STALE = 'stale';
    public const LOW = 'low';
    public const FAIR = 'fair';
    public const GOOD = 'good';
    public const TOP = 'top';

    public static function forScore(int $score): string
    {
        return match (true) {
            $score >= 80 => self::TOP,
            $score >= 60 => self::GOOD,
            $score >= 40 => self::FAIR,
            $score >= 20 => self::LOW,
            default => self::STALE,
        };
    }

    public static function label(string $band): string
    {
        return match ($band) {
            self::TOP => Craft::t('beacon', 'widgets.geoScore.80.100.label'),
            self::GOOD => Craft::t('beacon', 'widgets.geoScore.60.79.label'),
            self::FAIR => Craft::t('beacon', 'widgets.geoScore.40.59.label'),
            self::LOW => Craft::t('beacon', 'widgets.geoScore.20.39.label'),
            default => Craft::t('beacon', 'widgets.geoScore.0.19.label'),
        };
    }

    /** CSS suffix shared with widget bars and drill-down UI. */
    public static function barClass(string $band): string
    {
        return 'beacon-geo-score-widget-bar--' . $band;
    }
}
