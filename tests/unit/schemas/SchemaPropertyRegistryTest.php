<?php

namespace anvildev\beacon\tests\unit\schemas;

use anvildev\beacon\schemas\SchemaPropertyRegistry;
use PHPUnit\Framework\TestCase;

class SchemaPropertyRegistryTest extends TestCase
{
    public function testCoversTheCuratedSchemaTypes(): void
    {
        $types = SchemaPropertyRegistry::supportedTypes();
        foreach (['Article', 'Product', 'Recipe', 'HowTo', 'FAQPage', 'Review'] as $expected) {
            $this->assertContains($expected, $types);
        }
    }

    public function testEveryPropertyHasNameTierAndHelp(): void
    {
        foreach (SchemaPropertyRegistry::all() as $type => $props) {
            $this->assertNotEmpty($props, "Type {$type} has no properties");
            foreach ($props as $prop) {
                $this->assertArrayHasKey('name', $prop);
                $this->assertArrayHasKey('tier', $prop);
                $this->assertArrayHasKey('help', $prop);
                $this->assertContains($prop['tier'], ['required', 'recommended', 'optional']);
            }
        }
    }

    public function testForTypeReturnsEmptyArrayForUnknown(): void
    {
        $this->assertSame([], SchemaPropertyRegistry::forType('UnknownType'));
    }

    public function testArticleHasRequiredHeadlineAndImage(): void
    {
        $props = SchemaPropertyRegistry::forType('Article');
        $required = array_values(array_filter($props, static fn(array $p): bool => $p['tier'] === 'required'));
        $names = array_column($required, 'name');
        $this->assertContains('headline', $names);
        $this->assertContains('image', $names);
        $this->assertContains('datePublished', $names);
    }
}
