<?php

namespace anvildev\beacon\tests\unit\variables;

use anvildev\beacon\models\SeoMeta;
use anvildev\beacon\variables\BeaconVariable;
use PHPUnit\Framework\TestCase;
use ReflectionObject;

/**
 * Regression: craft.beacon.set(key, wrong-typed-value) must not crash head().
 *
 * Before the fix, typed SeoMeta property assignment raised an uncaught
 * TypeError from inside head(), which 500'd the page mid-<head> render.
 * The override-apply loop now wraps each assignment and silently drops bad
 * values, keeping the resolved defaults.
 */
class BeaconVariableSetTypeErrorTest extends TestCase
{
    public function testStringPassedForListRobotsDoesNotThrow(): void
    {
        $variable = new BeaconVariable();
        $meta = new SeoMeta();
        $meta->robots = ['index'];

        $this->applyOverrides($variable, $meta, ['robots' => 'noindex']);

        
        $this->assertSame(['index'], $meta->robots);
    }

    public function testArrayPassedForStringTitleDoesNotThrow(): void
    {
        
        
        $variable = new BeaconVariable();
        $meta = new SeoMeta();
        $meta->title = 'Original';

        $this->applyOverrides($variable, $meta, ['title' => ['nope']]);

        $this->assertSame('Original', $meta->title);
    }

    public function testNullPassedForRequiredArrayDoesNotThrow(): void
    {
        $variable = new BeaconVariable();
        $meta = new SeoMeta();
        $meta->openGraph = ['title' => 'x'];

        $this->applyOverrides($variable, $meta, ['openGraph' => null]);

        $this->assertSame(['title' => 'x'], $meta->openGraph);
    }

    public function testMatchedTypeOverrideStillApplied(): void
    {
        $variable = new BeaconVariable();
        $meta = new SeoMeta();
        $meta->title = 'Original';
        $meta->robots = ['index'];

        $this->applyOverrides($variable, $meta, [
            'title' => 'Overridden',
            'robots' => ['noindex'],
        ]);

        $this->assertSame('Overridden', $meta->title);
        $this->assertSame(['noindex'], $meta->robots);
    }

    public function testUnknownKeyIsIgnored(): void
    {
        $variable = new BeaconVariable();
        $meta = new SeoMeta();
        $meta->title = 'Original';

        $this->applyOverrides($variable, $meta, ['notARealField' => 'x']);

        $this->assertSame('Original', $meta->title);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function applyOverrides(BeaconVariable $variable, SeoMeta $meta, array $overrides): void
    {
        $ref = new ReflectionObject($variable);
        $method = $ref->getMethod('applyOverrides');
        $method->setAccessible(true);
        $method->invoke($variable, $meta, $overrides);
    }
}
