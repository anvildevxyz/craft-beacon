<?php

namespace anvildev\beacon\tests\unit\variables;

use anvildev\beacon\models\Schema;
use anvildev\beacon\variables\BeaconVariable;
use PHPUnit\Framework\TestCase;
use ReflectionObject;
use yii\console\Request as ConsoleRequest;

/**
 * Covers the schema-graph assembly tail: ad-hoc bundle construction and the
 * identity/finalize pipeline. The non-web request branch skips the
 * EVENT_DEFINE_SCHEMAS trigger, so the graph must pass through unchanged;
 * the web-request event path needs a booted app and lives in integration.
 */
class BeaconVariableSchemaGraphTest extends TestCase
{
    public function testBuildAdHocBundleMapsSchemaRows(): void
    {
        $bundle = $this->invoke(new BeaconVariable(), 'buildAdHocBundle', [[
            new Schema(1, 'article', 'Article', ['headline' => 'title'], 1, true),
            new Schema(2, 'article', 'Product', [], 2, true),
        ]]);

        $this->assertSame('article', $bundle->entryTypeHandle);
        $this->assertSame([
            ['type' => 'Article', 'mapping' => ['headline' => 'title']],
            ['type' => 'Product', 'mapping' => []],
        ], $bundle->schemas);
    }

    public function testBuildAdHocBundleWithNoRowsYieldsEmptyBundle(): void
    {
        $bundle = $this->invoke(new BeaconVariable(), 'buildAdHocBundle', [[]]);

        $this->assertSame('', $bundle->entryTypeHandle);
        $this->assertSame([], $bundle->schemas);
    }

    public function testFinalizeSchemaGraphPassesNodesThroughForNonWebRequest(): void
    {
        $nodes = [['@type' => 'Article'], ['@type' => 'BreadcrumbList']];

        $this->assertSame(
            $nodes,
            $this->invoke(new BeaconVariable(), 'finalizeSchemaGraph', [$nodes, null, new ConsoleRequest()]),
        );
    }

    public function testAppendIdentityAndFinalizeAddsNothingWithoutCraft(): void
    {
        $base = [['@type' => 'Article']];

        $this->assertSame(
            $base,
            $this->invoke(new BeaconVariable(), 'appendIdentityAndFinalize', [$base, null, new ConsoleRequest()]),
        );
    }

    /** @param array<int,mixed> $args */
    private function invoke(object $obj, string $name, array $args = []): mixed
    {
        $method = (new ReflectionObject($obj))->getMethod($name);
        $method->setAccessible(true);
        return $method->invokeArgs($obj, $args);
    }
}
