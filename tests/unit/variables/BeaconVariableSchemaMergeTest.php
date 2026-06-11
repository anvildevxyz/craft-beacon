<?php

namespace anvildev\beacon\tests\unit\variables;

use anvildev\beacon\variables\BeaconVariable;
use PHPUnit\Framework\TestCase;
use ReflectionObject;

/**
 * Tests for BeaconVariable's schema-merge behavior. Bypasses the Craft-dependent
 * resolveSchemas() path by populating $cachedSchemas + $oneOffSchemas via
 * reflection, then asserting the merge result.
 */
class BeaconVariableSchemaMergeTest extends TestCase
{
    public function testAddSchemaPreservesOneOffsAfterCacheReset(): void
    {
        $var = new BeaconVariable();

        
        $this->setProperty($var, 'cachedSchemas', [['@type' => 'Article']]);

        
        $var->addSchema(['@type' => 'BreadcrumbList']);

        
        $merged = $var->schemas();

        $oneOffs = $this->getProperty($var, 'oneOffSchemas');
        $cached = $this->getProperty($var, 'cachedSchemas');

        $this->assertSame([['@type' => 'Article'], ['@type' => 'BreadcrumbList']], $merged);
        $this->assertCount(1, $oneOffs);
        $this->assertSame('BreadcrumbList', $oneOffs[0]['@type']);
        $this->assertCount(1, $cached);
        $this->assertSame('Article', $cached[0]['@type']);
    }

    public function testAddSchemaDoesNotInvalidateCachedBundleSchemas(): void
    {
        $var = new BeaconVariable();
        $this->setProperty($var, 'cachedSchemas', [['@type' => 'Article']]);

        $var->addSchema(['@type' => 'X']);

        
        $this->assertNotNull($this->getProperty($var, 'cachedSchemas'));
    }

    private function setProperty(object $obj, string $name, mixed $value): void
    {
        $ref = new ReflectionObject($obj);
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($obj, $value);
    }

    private function getProperty(object $obj, string $name): mixed
    {
        $ref = new ReflectionObject($obj);
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        return $prop->getValue($obj);
    }
}
