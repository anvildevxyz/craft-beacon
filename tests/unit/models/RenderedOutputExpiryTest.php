<?php

namespace anvildev\beacon\tests\unit\models;

use anvildev\beacon\helpers\Db;
use anvildev\beacon\models\RenderedOutput;
use DateTime;
use PHPUnit\Framework\TestCase;

/**
 * Pins the render-cache TTL contract: a null validUntil never expires
 * (event-driven invalidation only); a set validUntil expires strictly after
 * it lapses. RenderCacheService::get() treats an expired row as a miss.
 */
class RenderedOutputExpiryTest extends TestCase
{
    private DateTime $now;

    protected function setUp(): void
    {
        $this->now = new DateTime('2026-06-09 12:00:00');
    }

    public function testNullValidUntilNeverExpires(): void
    {
        $output = new RenderedOutput('body', new DateTime('2020-01-01 00:00:00'), null);
        $this->assertFalse($output->isExpired($this->now));
    }

    public function testPastValidUntilIsExpired(): void
    {
        $output = new RenderedOutput('body', new DateTime('2026-06-09 11:00:00'), new DateTime('2026-06-09 11:30:00'));
        $this->assertTrue($output->isExpired($this->now));
    }

    public function testFutureValidUntilIsFresh(): void
    {
        $output = new RenderedOutput('body', new DateTime('2026-06-09 11:00:00'), new DateTime('2026-06-09 12:30:00'));
        $this->assertFalse($output->isExpired($this->now));
    }

    public function testExactBoundaryIsNotExpired(): void
    {
        $output = new RenderedOutput('body', new DateTime('2026-06-09 11:00:00'), new DateTime('2026-06-09 12:00:00'));
        $this->assertFalse($output->isExpired($this->now));
    }

    public function testDbFutureProducesDbTimestampAheadOfNow(): void
    {
        $future = Db::future(1800);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $future);
        $this->assertGreaterThan(Db::now(), $future);
    }
}
