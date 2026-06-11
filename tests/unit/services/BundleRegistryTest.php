<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\models\Schema;
use anvildev\beacon\services\BundleRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionObject;

class BundleRegistryTest extends TestCase
{
    public function testGroupsByEntryType(): void
    {
        $registry = new BundleRegistry();
        $schemas = [
            new Schema(1, 'article', 'Article', ['headline' => '{title}'], 0, true),
            new Schema(2, 'article', 'BreadcrumbList', [], 1, true),
            new Schema(3, 'product', 'Product', ['name' => '{title}'], 0, true),
        ];

        // Pre-populate the internal cache so getSchemasForEntryType bypasses the DB.
        $grouped = [];
        foreach ($schemas as $schema) {
            $grouped[$schema->entryTypeHandle][] = $schema;
        }
        $this->injectCache($registry, $grouped);

        $articleSchemas = $registry->getSchemasForEntryType('article');
        $productSchemas = $registry->getSchemasForEntryType('product');

        $this->assertCount(2, $articleSchemas);
        $this->assertCount(1, $productSchemas);
        $this->assertSame('Article', $articleSchemas[0]->schemaType);
    }

    public function testEmptyInputYieldsEmptyArray(): void
    {
        $registry = new BundleRegistry();
        $this->injectCache($registry, []);

        $this->assertSame([], $registry->getSchemasForEntryType('anything'));
    }

    /** @param array<string, list<Schema>> $cache */
    private function injectCache(BundleRegistry $registry, array $cache): void
    {
        $ref = new ReflectionObject($registry);
        $prop = $ref->getProperty('cached');
        $prop->setAccessible(true);
        $prop->setValue($registry, $cache);
    }
}
