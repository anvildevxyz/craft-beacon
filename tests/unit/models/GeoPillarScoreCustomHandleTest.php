<?php

namespace anvildev\beacon\tests\unit\models;

use anvildev\beacon\enums\GeoScorePillar;
use anvildev\beacon\models\GeoPillarScore;
use PHPUnit\Framework\TestCase;

/**
 * Covers the custom (string-handle) pillar path added so third-party pillars
 * registered via RegisterGeoScorePillarsEvent can add a brand-new pillar, not
 * just override a built-in one.
 */
final class GeoPillarScoreCustomHandleTest extends TestCase
{
    public function testBuiltInEnumPillarHandleAndLabel(): void
    {
        $s = new GeoPillarScore(GeoScorePillar::FactDensity, 7, GeoPillarScore::BAND_GOOD);
        $this->assertSame('factDensity', $s->pillarHandle());
        $this->assertSame(GeoScorePillar::FactDensity->label(), $s->label());
        $this->assertSame('factDensity', $s->toArray()['pillar']);
    }

    public function testCustomStringPillarHandleAndLabel(): void
    {
        $s = new GeoPillarScore('readabilityScore', 9, GeoPillarScore::BAND_TOP, ['nice']);
        // A custom handle survives unchanged through handle/label/toArray.
        $this->assertSame('readabilityScore', $s->pillarHandle());
        $this->assertSame('readabilityScore', $s->label());
        $this->assertSame('readabilityScore', $s->toArray()['pillar']);
        $this->assertSame(9, $s->toArray()['score']);
        $this->assertSame(['nice'], $s->toArray()['notes']);
    }
}
