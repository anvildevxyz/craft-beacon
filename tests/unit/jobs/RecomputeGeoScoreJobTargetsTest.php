<?php

namespace anvildev\beacon\tests\unit\jobs;

use anvildev\beacon\jobs\RecomputeGeoScoreJob;
use PHPUnit\Framework\TestCase;

/**
 * The job carries a batch of (element, site) pairs, but must still run jobs
 * that were serialised by an earlier version with the single-pair fields and
 * are sitting in the queue across an upgrade.
 */
class RecomputeGeoScoreJobTargetsTest extends TestCase
{
    public function testBatchedPairsAreTheTargets(): void
    {
        $job = new RecomputeGeoScoreJob(['pairs' => [[10, 1], [11, 2]]]);

        $this->assertSame([[10, 1], [11, 2]], $this->targets($job));
    }

    public function testLegacySinglePairFieldsStillResolve(): void
    {
        $job = new RecomputeGeoScoreJob(['elementId' => 42, 'siteId' => 3]);

        $this->assertSame([[42, 3]], $this->targets($job));
    }

    public function testBatchWinsOverLegacyFields(): void
    {
        $job = new RecomputeGeoScoreJob([
            'pairs' => [[7, 1]],
            'elementId' => 42,
            'siteId' => 3,
        ]);

        $this->assertSame([[7, 1]], $this->targets($job));
    }

    public function testEmptyJobHasNoTargets(): void
    {
        $this->assertSame([], $this->targets(new RecomputeGeoScoreJob()));
    }

    /**
     * An unset legacy pair must not resolve to a target — element/site id 0
     * would send the job looking for an entry that cannot exist.
     */
    public function testZeroedLegacyFieldsAreNotATarget(): void
    {
        $this->assertSame([], $this->targets(new RecomputeGeoScoreJob(['elementId' => 5, 'siteId' => 0])));
        $this->assertSame([], $this->targets(new RecomputeGeoScoreJob(['elementId' => 0, 'siteId' => 5])));
    }

    /**
     * @return list<array{int, int}>
     */
    private function targets(RecomputeGeoScoreJob $job): array
    {
        $method = new \ReflectionMethod($job, 'targets');
        $method->setAccessible(true);

        /** @var list<array{int, int}> $result */
        $result = $method->invoke($job);

        return $result;
    }
}
