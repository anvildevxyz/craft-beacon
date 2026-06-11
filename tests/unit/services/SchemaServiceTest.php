<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\models\SchemaBundle;
use anvildev\beacon\schemas\SchemaTemplate;
use anvildev\beacon\services\ExpressionEvaluator;
use anvildev\beacon\services\SchemaService;
use PHPUnit\Framework\TestCase;

class SchemaServiceTest extends TestCase
{
    private function service(): SchemaService
    {
        $evaluator = new ExpressionEvaluator();
        return new SchemaService([
            'Article' => fn() => new SchemaTemplate($evaluator, 'Article'),
        ]);
    }

    public function testRendersBundleSchemas(): void
    {
        $bundle = new SchemaBundle();
        $bundle->schemas = [['type' => 'Article', 'mapping' => ['headline' => '{title}']]];
        $context = ['title' => 'Hello'];

        $output = $this->service()->render($bundle, [], $context);

        $this->assertCount(1, $output);
        $this->assertSame('Article', $output[0]['@type']);
        $this->assertSame('Hello', $output[0]['headline']);
    }

    public function testAppendsPerEntryAddons(): void
    {
        $bundle = new SchemaBundle();
        $bundle->schemas = [['type' => 'Article', 'mapping' => ['headline' => '{title}']]];
        $addons = [['type' => 'Article', 'mapping' => ['headline' => 'Custom']]];
        $context = ['title' => 'Hello'];

        $output = $this->service()->render($bundle, $addons, $context);

        $this->assertCount(2, $output);
        $this->assertSame('Hello', $output[0]['headline']);
        $this->assertSame('Custom', $output[1]['headline']);
    }

    public function testIgnoresUnknownSchemaTypes(): void
    {
        $bundle = new SchemaBundle();
        $bundle->schemas = [['type' => 'NonexistentSchemaType']];

        $output = $this->service()->render($bundle, [], []);
        $this->assertCount(0, $output);
    }
}
