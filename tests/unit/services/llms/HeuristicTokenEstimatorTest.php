<?php

namespace anvildev\beacon\tests\unit\services\llms;

use anvildev\beacon\services\llms\HeuristicTokenEstimator;
use PHPUnit\Framework\TestCase;

class HeuristicTokenEstimatorTest extends TestCase
{
    private function estimator(): HeuristicTokenEstimator
    {
        return new HeuristicTokenEstimator();
    }

    public function testEmptyAndWhitespaceReturnZero(): void
    {
        $this->assertSame(0, $this->estimator()->estimate(''));
        $this->assertSame(0, $this->estimator()->estimate("   \n\t  "));
    }

    public function testNonEmptyReturnsAtLeastOne(): void
    {
        $this->assertGreaterThanOrEqual(1, $this->estimator()->estimate('a'));
    }

    public function testLongerTextEstimatesMoreTokens(): void
    {
        $short = $this->estimator()->estimate(str_repeat('word ', 10));
        $long = $this->estimator()->estimate(str_repeat('word ', 100));
        $this->assertGreaterThan($short, $long);
    }

    public function testApproximationStaysWithinSaneBounds(): void
    {
        // 100 five-letter words. A real tokenizer lands ~100–160 tokens here;
        // the heuristic must be in the same ballpark (not off by an order of
        // magnitude in either direction).
        $tokens = $this->estimator()->estimate(str_repeat('apple ', 100));
        $this->assertGreaterThan(80, $tokens);
        $this->assertLessThan(220, $tokens);
    }

    public function testScalesRoughlyLinearly(): void
    {
        $one = $this->estimator()->estimate(str_repeat('alpha ', 50));
        $two = $this->estimator()->estimate(str_repeat('alpha ', 100));
        // Doubling the input should roughly double the estimate (±25%).
        $ratio = $two / max(1, $one);
        $this->assertGreaterThan(1.6, $ratio);
        $this->assertLessThan(2.4, $ratio);
    }
}
