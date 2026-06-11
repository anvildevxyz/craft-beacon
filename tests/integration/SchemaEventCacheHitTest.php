<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\events\DefineSchemasEvent;
use anvildev\beacon\Plugin;
use anvildev\beacon\variables\BeaconVariable;
use craft\test\TestCase;
use ReflectionObject;
use yii\base\Event;

/**
 * Locks in the fix for the cached-bundle event-skip bug: the
 * `Plugin::EVENT_DEFINE_SCHEMAS` event must fire on every `beacon.schemas()`
 * call, including when the bundle render is served from the same-request
 * cache.
 *
 * @group requires-craft
 */
class SchemaEventCacheHitTest extends TestCase
{
    public function testEventFiresOnCacheHit(): void
    {
        $variable = new BeaconVariable();

        $ref = new ReflectionObject($variable);
        $cachedSchemas = $ref->getProperty('cachedSchemas');
        $cachedSchemas->setAccessible(true);
        $cachedSchemas->setValue($variable, [
            ['@type' => 'WebPage', 'name' => 'Cached'],
        ]);

        $resolveSchemas = $ref->getMethod('resolveSchemas');
        $resolveSchemas->setAccessible(true);

        $fireCount = 0;
        $handler = function(DefineSchemasEvent $e) use (&$fireCount): void {
            $fireCount++;
            
            $e->holder->nodes = array_values(array_filter(
                $e->holder->nodes,
                fn(array $n) => ($n['@id'] ?? null) !== 'extra-1',
            ));
            $e->holder->nodes[] = ['@id' => 'extra-1', '@type' => 'Person', 'name' => 'Listener'];
        };
        Event::on(Plugin::class, Plugin::EVENT_DEFINE_SCHEMAS, $handler);

        try {
            /** @var list<array<string,mixed>> $first */
            $first = $resolveSchemas->invoke($variable);
            /** @var list<array<string,mixed>> $second */
            $second = $resolveSchemas->invoke($variable);
        } finally {
            Event::off(Plugin::class, Plugin::EVENT_DEFINE_SCHEMAS, $handler);
        }

        $extraNodes = static fn(array $nodes): array => array_values(array_filter(
            $nodes,
            fn(array $n) => ($n['@id'] ?? null) === 'extra-1',
        ));

        $this->assertSame(2, $fireCount, 'event must fire on each resolve, even when bundle is cached');
        // Assert on the listener's own node — total count includes identity/finalize nodes that vary by site.
        $this->assertCount(1, $extraNodes($first), 'first call: listener node present exactly once');
        $this->assertCount(1, $extraNodes($second), 'second call: listener replaced, not appended');
        $this->assertContains(['@id' => 'extra-1', '@type' => 'Person', 'name' => 'Listener'], $first);
    }
}
