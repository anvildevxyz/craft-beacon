<?php

namespace anvildev\beacon\services\scoring;

use anvildev\beacon\enums\GeoScorePillar;
use anvildev\beacon\models\GeoPillarScore;

/**
 * Contract for a single pillar of the composite GEO score. Implementations
 * are typically stateless services with one public method —
 * {@see self::compute()} — that returns a 0–10 banded score with
 * actionable feedback notes.
 *
 * Built-in pillars live under `services/scoring/`; third-party pillars
 * register through {@see \anvildev\beacon\events\RegisterGeoScorePillarsEvent}.
 *
 * The {@see PillarContext} argument carries the target element, site, and
 * a lazy-evaluated content AST shared across structural pillars in one
 * compute pass — pillars that need only element metadata (Freshness,
 * Entity completeness) ignore the AST accessor; pillars that need content
 * (Claim-based headings, Chunkability, etc.) call `$ctx->ast()`.
 */
interface PillarComputerInterface
{
    /**
     * Built-in pillars return a {@see GeoScorePillar} case; a third-party pillar
     * registered via {@see \anvildev\beacon\events\RegisterGeoScorePillarsEvent}
     * may return a plain string handle to add a brand-new pillar (default weight
     * 1.0 unless overridden via `Settings::$geoScorePillarWeights`).
     */
    public function pillar(): GeoScorePillar|string;

    public function compute(PillarContext $ctx): GeoPillarScore;
}
