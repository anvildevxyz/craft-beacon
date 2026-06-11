<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\services\ExpressionEvaluator;
use PHPUnit\Framework\TestCase;

class ExpressionEvaluatorTest extends TestCase
{
    public function testInterpolatesSimpleProperty(): void
    {
        $evaluator = new ExpressionEvaluator();
        $context = ['title' => 'Hello'];
        $this->assertSame('Hello', $evaluator->interpolate('{title}', $context));
    }

    public function testInterpolatesNestedProperty(): void
    {
        $evaluator = new ExpressionEvaluator();
        $context = ['seo' => ['description' => 'A description']];
        $this->assertSame('A description', $evaluator->interpolate('{seo.description}', $context));
    }

    public function testInterpolatesArrayIndex(): void
    {
        $evaluator = new ExpressionEvaluator();
        $context = ['authors' => [['name' => 'Jane']]];
        $this->assertSame('Jane', $evaluator->interpolate('{authors.0.name}', $context));
    }

    public function testInterpolationLeavesUnmatchedTokensEmpty(): void
    {
        $evaluator = new ExpressionEvaluator();
        $context = [];
        $this->assertSame('', $evaluator->interpolate('{missing}', $context));
    }

    public function testInterpolatesMultipleTokens(): void
    {
        $evaluator = new ExpressionEvaluator();
        $context = ['title' => 'Hello', 'siteName' => 'Site'];
        $this->assertSame('Hello | Site', $evaluator->interpolate('{title} | {siteName}', $context));
    }

    public function testInterpolateRefusesObjectPropertyTraversal(): void
    {
        $evaluator = new ExpressionEvaluator();
        $obj = new \stdClass();
        $obj->password = 'secret';
        $context = ['entry' => $obj];

        $this->assertSame('', $evaluator->interpolate('{entry.password}', $context));
    }

    public function testInterpolateReturnsEmptyForArrayLeaf(): void
    {
        $evaluator = new ExpressionEvaluator();
        $context = ['authors' => [['name' => 'Jane']]];
        $this->assertSame('', $evaluator->interpolate('{authors}', $context));
    }
}
