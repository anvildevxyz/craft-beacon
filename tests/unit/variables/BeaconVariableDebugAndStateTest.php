<?php

namespace anvildev\beacon\tests\unit\variables;

use anvildev\beacon\models\SeoMeta;
use anvildev\beacon\variables\BeaconVariable;
use PHPUnit\Framework\TestCase;
use ReflectionObject;

/**
 * Covers the request-state accessors (debug(), tags(), getMeta()/meta(),
 * set(), schemas()) against a seeded request-cached SeoMeta, bypassing the
 * Craft-dependent resolveMetaUncached() path.
 */
class BeaconVariableDebugAndStateTest extends TestCase
{
    public function testDebugReportsCountsCacheStatusAndTags(): void
    {
        $variable = new BeaconVariable();
        $meta = new SeoMeta();
        $meta->title = 'Title';
        $meta->description = 'Description';
        $meta->robots = ['noindex', 'nofollow'];
        $meta->sourceMap = ['title' => 'entry'];
        $this->setProperty($variable, 'cachedMeta', $meta);
        $variable->addSchema(['@type' => 'Article']);

        $debug = $variable->debug();

        $this->assertNull($debug['route']);
        // title + description + robots
        $this->assertSame(3, $debug['tagCount']);
        $this->assertSame(1, $debug['schemaCount']);
        $this->assertSame('request-cache-hit', $debug['metaCache']);
        $this->assertSame('disabled', $debug['schemaCache']);
        $this->assertSame(['title' => 'entry'], $debug['sourceMap']);
        $this->assertSame(['noindex', 'nofollow'], $debug['robots']);
        $this->assertSame('noindex, nofollow', $debug['tags']['robots']['content']);
    }

    public function testTagsReflectsResolvedMetaAndOverrides(): void
    {
        $variable = new BeaconVariable();
        $meta = new SeoMeta();
        $meta->description = 'Original';
        $this->setProperty($variable, 'cachedMeta', $meta);
        $variable->setTag('og:see_also', 'https://example.com');
        $variable->removeTag('description');

        $tags = $variable->tags();

        $this->assertArrayNotHasKey('description', $tags);
        $this->assertSame(
            ['attr' => 'property', 'name' => 'og:see_also', 'content' => 'https://example.com'],
            $tags['og:see_also'],
        );
    }

    public function testGetMetaAndMetaAliasReturnTheCachedInstance(): void
    {
        $variable = new BeaconVariable();
        $meta = new SeoMeta();
        $this->setProperty($variable, 'cachedMeta', $meta);

        $this->assertSame($meta, $variable->getMeta());
        $this->assertSame($meta, $variable->meta());
    }

    public function testSetStoresOverrideAndInvalidatesCachedMeta(): void
    {
        $variable = new BeaconVariable();
        $this->setProperty($variable, 'cachedMeta', new SeoMeta());

        $variable->set('title', 'Overridden');

        $this->assertNull($this->getProperty($variable, 'cachedMeta'));
        $this->assertSame(['title' => 'Overridden'], $this->getProperty($variable, 'overrides'));
    }

    public function testSchemasMergesCachedAndOneOffWithoutCraft(): void
    {
        $variable = new BeaconVariable();
        $this->setProperty($variable, 'cachedSchemas', [['@type' => 'Article']]);
        $variable->addSchema(['@type' => 'BreadcrumbList']);

        $this->assertSame(
            [['@type' => 'Article'], ['@type' => 'BreadcrumbList']],
            $variable->schemas(),
        );
        $this->assertSame('disabled', $this->getProperty($variable, 'schemaCacheStatus'));
    }

    public function testLogMetaDebugHonorsEnvSwitch(): void
    {
        putenv('BEACON_META_DEBUG=1');
        try {
            $this->assertTrue($this->invoke(new BeaconVariable(), 'logMetaDebug'));
        } finally {
            putenv('BEACON_META_DEBUG');
        }
    }

    private function setProperty(object $obj, string $name, mixed $value): void
    {
        $prop = (new ReflectionObject($obj))->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($obj, $value);
    }

    private function getProperty(object $obj, string $name): mixed
    {
        $prop = (new ReflectionObject($obj))->getProperty($name);
        $prop->setAccessible(true);
        return $prop->getValue($obj);
    }

    /** @param array<int,mixed> $args */
    private function invoke(object $obj, string $name, array $args = []): mixed
    {
        $method = (new ReflectionObject($obj))->getMethod($name);
        $method->setAccessible(true);
        return $method->invokeArgs($obj, $args);
    }
}
