<?php

namespace anvildev\beacon\tests\unit\helpers\links;

use anvildev\beacon\helpers\links\CosineSimilarity;
use PHPUnit\Framework\TestCase;

class CosineSimilarityTest extends TestCase
{
    public function testIdenticalVectors(): void
    {
        $vector = ['a' => 1.0, 'b' => 2.0, 'c' => 3.0];
        $this->assertEqualsWithDelta(1.0, CosineSimilarity::calculate($vector, $vector), 0.0001);
    }

    public function testOrthogonalVectors(): void
    {
        $a = ['x' => 1.0, 'y' => 0.0];
        $b = ['x' => 0.0, 'y' => 1.0];
        $this->assertEqualsWithDelta(0.0, CosineSimilarity::calculate($a, $b), 0.0001);
    }

    public function testNoOverlapVectors(): void
    {
        $a = ['craft' => 1.5, 'cms' => 2.0];
        $b = ['wordpress' => 1.0, 'theme' => 3.0];
        $this->assertSame(0.0, CosineSimilarity::calculate($a, $b));
    }

    public function testPartialOverlap(): void
    {
        $a = ['craft' => 1.0, 'cms' => 2.0, 'plugin' => 1.0];
        $b = ['craft' => 1.0, 'seo' => 3.0];
        $result = CosineSimilarity::calculate($a, $b);
        $this->assertGreaterThan(0.0, $result);
        $this->assertLessThan(1.0, $result);
    }

    public function testEmptyVectors(): void
    {
        $this->assertSame(0.0, CosineSimilarity::calculate([], []));
        $this->assertSame(0.0, CosineSimilarity::calculate(['a' => 1.0], []));
        $this->assertSame(0.0, CosineSimilarity::calculate([], ['a' => 1.0]));
    }

    public function testFloatArrayCosine(): void
    {
        $a = [1.0, 0.0];
        $b = [0.5, 0.866];
        $result = CosineSimilarity::calculateFromArrays($a, $b);
        $this->assertEqualsWithDelta(0.5, $result, 0.01);
    }

    public function testFloatArrayIdentical(): void
    {
        $v = [0.1, 0.2, 0.3, 0.4];
        $result = CosineSimilarity::calculateFromArrays($v, $v);
        $this->assertEqualsWithDelta(1.0, $result, 0.0001);
    }

    public function testFloatArrayDifferentLengths(): void
    {
        $a = [1.0, 2.0];
        $b = [1.0, 2.0, 3.0];
        $this->assertSame(0.0, CosineSimilarity::calculateFromArrays($a, $b));
    }

    public function testFloatArrayEmpty(): void
    {
        $this->assertSame(0.0, CosineSimilarity::calculateFromArrays([], []));
    }
}
