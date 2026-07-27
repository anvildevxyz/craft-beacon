<?php

namespace anvildev\beacon\tests\unit\jobs;

use anvildev\beacon\jobs\RecomputeGeoScoreJob;
use craft\queue\QueueInterface;
use PHPUnit\Framework\TestCase;

/**
 * Batching many (element, site) pairs into one job replaced the isolation that
 * one-job-per-element gave for free: without a guard, the first pair that
 * throws would abandon every pair behind it, and per-pair progress writes would
 * hand back the queue round-trips batching was meant to save.
 */
class RecomputeGeoScoreJobBatchTest extends TestCase
{
    public function testOneFailingPairDoesNotAbortTheRestOfTheBatch(): void
    {
        $job = new class(['pairs' => [[10, 1], [11, 1], [12, 1]]]) extends RecomputeGeoScoreJob {
            /** @var list<int> */
            public array $seen = [];

            protected function recompute(int $elementId, int $siteId): void
            {
                $this->seen[] = $elementId;

                if ($elementId === 11) {
                    throw new \RuntimeException('rescoring blew up');
                }
            }
        };

        $job->execute($this->createMock(QueueInterface::class));

        $this->assertSame([10, 11, 12], $job->seen, 'pairs after a failure must still be rescored');
    }

    public function testProgressIsNotWrittenOncePerPair(): void
    {
        $pairs = array_map(static fn(int $i): array => [$i, 1], range(1, 25));

        $calls = 0;
        $queue = $this->createMock(QueueInterface::class);
        $queue->method('setProgress')->willReturnCallback(function() use (&$calls): void {
            $calls++;
        });

        $this->noopJob($pairs)->execute($queue);

        $this->assertGreaterThan(0, $calls, 'a long batch should still report progress');
        $this->assertLessThanOrEqual(5, $calls, 'progress must be throttled, not written per pair');
    }

    public function testSinglePairBatchSkipsProgressEntirely(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects($this->never())->method('setProgress');

        $this->noopJob([[10, 1]])->execute($queue);
    }

    /**
     * A job whose rescoring is a no-op, so only the batch mechanics are under
     * test.
     *
     * @param list<array{int, int}> $pairs
     */
    private function noopJob(array $pairs): RecomputeGeoScoreJob
    {
        return new class(['pairs' => $pairs]) extends RecomputeGeoScoreJob {
            protected function recompute(int $elementId, int $siteId): void
            {
            }
        };
    }
}
