<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\enums\GeoScorePillar;
use anvildev\beacon\models\GeoPillarScore;
use anvildev\beacon\models\GeoScore;
use craft\test\TestCase;
use DateTimeImmutable;

class GeoScoreLogicTest extends TestCase
{
    public function testBandForMapsScoreToBand(): void
    {
        $this->assertSame(GeoPillarScore::BAND_TOP, GeoPillarScore::bandFor(10));
        $this->assertSame(GeoPillarScore::BAND_TOP, GeoPillarScore::bandFor(8));
        $this->assertSame(GeoPillarScore::BAND_GOOD, GeoPillarScore::bandFor(7));
        $this->assertSame(GeoPillarScore::BAND_GOOD, GeoPillarScore::bandFor(5));
        $this->assertSame(GeoPillarScore::BAND_LOW, GeoPillarScore::bandFor(4));
        $this->assertSame(GeoPillarScore::BAND_LOW, GeoPillarScore::bandFor(2));
        $this->assertSame(GeoPillarScore::BAND_STALE, GeoPillarScore::bandFor(1));
        $this->assertSame(GeoPillarScore::BAND_STALE, GeoPillarScore::bandFor(0));
    }

    public function testWeakestPillarReturnsLowestScoringPillar(): void
    {
        $score = new GeoScore(
            score: 62,
            pillars: [
                GeoScorePillar::FreshnessBanding->value => new GeoPillarScore(
                    GeoScorePillar::FreshnessBanding,
                    7,
                    GeoPillarScore::BAND_GOOD,
                ),
                GeoScorePillar::EntityCompleteness->value => new GeoPillarScore(
                    GeoScorePillar::EntityCompleteness,
                    3,
                    GeoPillarScore::BAND_LOW,
                ),
            ],
            computedAt: new DateTimeImmutable(),
        );

        $this->assertSame(GeoScorePillar::EntityCompleteness, $score->weakestPillar());
    }

    public function testWeakestPillarTieBreaksByEnumOrder(): void
    {
        // Two pillars tied at 4. Enum order is FreshnessBanding first, so it wins.
        $score = new GeoScore(
            score: 40,
            pillars: [
                GeoScorePillar::FreshnessBanding->value => new GeoPillarScore(
                    GeoScorePillar::FreshnessBanding,
                    4,
                    GeoPillarScore::BAND_LOW,
                ),
                GeoScorePillar::EntityCompleteness->value => new GeoPillarScore(
                    GeoScorePillar::EntityCompleteness,
                    4,
                    GeoPillarScore::BAND_LOW,
                ),
            ],
            computedAt: new DateTimeImmutable(),
        );

        $this->assertSame(GeoScorePillar::FreshnessBanding, $score->weakestPillar());
    }

    public function testWeakestPillarReturnsNullForEmptyScore(): void
    {
        $score = new GeoScore(
            score: 0,
            pillars: [],
            computedAt: new DateTimeImmutable(),
        );

        $this->assertNull($score->weakestPillar());
    }

    public function testAllPillarsHavePositiveDefaultWeight(): void
    {
        foreach (GeoScorePillar::cases() as $pillar) {
            $this->assertGreaterThan(0.0, $pillar->defaultWeight(), sprintf(
                'Pillar %s must have a positive default weight (got %.2f)',
                $pillar->value,
                $pillar->defaultWeight(),
            ));
        }
    }

    public function testGeoPillarScoreToArrayOmitsEmptyDebug(): void
    {
        $pillar = new GeoPillarScore(
            GeoScorePillar::FreshnessBanding,
            10,
            GeoPillarScore::BAND_TOP,
            ['Updated within the last 30 days — top freshness band.'],
        );

        $array = $pillar->toArray();
        $this->assertArrayNotHasKey('debug', $array);
        $this->assertSame('freshnessBanding', $array['pillar']);
        $this->assertSame(10, $array['score']);
        $this->assertSame(GeoPillarScore::BAND_TOP, $array['band']);
    }

    public function testGeoPillarScoreToArrayIncludesNonEmptyDebug(): void
    {
        $pillar = new GeoPillarScore(
            GeoScorePillar::FreshnessBanding,
            7,
            GeoPillarScore::BAND_GOOD,
            [],
            ['ageDays' => 60],
        );

        $array = $pillar->toArray();
        $this->assertArrayHasKey('debug', $array);
        $this->assertSame(['ageDays' => 60], $array['debug'] ?? null);
    }
}
