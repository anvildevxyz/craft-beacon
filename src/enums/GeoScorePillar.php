<?php

namespace anvildev\beacon\enums;

use Craft;

/**
 * The six pillars that compose a Beacon GEO score. Default weights derive
 * from relative effect sizes in `docs/GEO_CONTENT_SCORING.md`. Operators can
 * override weights via `Settings::$geoScorePillarWeights` keyed by handle.
 *
 * The handle is intentionally stable across versions; the label is
 * translated at read time. Pillar computers register against the handle
 * (whether built-in or third-party via {@see
 * \anvildev\beacon\events\RegisterGeoScorePillarsEvent}).
 */
enum GeoScorePillar: string
{
    case FreshnessBanding = 'freshnessBanding';
    case EntityCompleteness = 'entityCompleteness';
    case ClaimBasedHeadings = 'claimBasedHeadings';
    case Chunkability = 'chunkability';
    case FactDensity = 'factDensity';
    case OutboundCitationDensity = 'outboundCitationDensity';

    /**
     * Relative weight in the composite 0–100 score. Sum across all pillars
     * is normalised at compute time, so these are ratios not percentages.
     */
    public function defaultWeight(): float
    {
        return match ($this) {
            self::OutboundCitationDensity => 2.3,
            self::FactDensity => 1.6,
            self::Chunkability => 1.3,
            self::ClaimBasedHeadings => 1.2,
            self::FreshnessBanding => 1.0,
            self::EntityCompleteness => 1.0,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::FreshnessBanding => Craft::t('beacon', 'Freshness'),
            self::EntityCompleteness => Craft::t('beacon', 'Entity completeness'),
            self::ClaimBasedHeadings => Craft::t('beacon', 'Claim-based headings'),
            self::Chunkability => Craft::t('beacon', 'Chunkability'),
            self::FactDensity => Craft::t('beacon', 'Fact density'),
            self::OutboundCitationDensity => Craft::t('beacon', 'Outbound citations'),
        };
    }
}
