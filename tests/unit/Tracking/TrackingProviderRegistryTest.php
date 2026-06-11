<?php

namespace anvildev\beacon\tests\unit\Tracking;

use anvildev\beacon\events\RegisterTrackingProvidersEvent;
use anvildev\beacon\services\TrackingProviderRegistry;
use anvildev\beacon\tracking\providers\CustomScriptProvider;
use anvildev\beacon\tracking\providers\GA4Provider;
use PHPUnit\Framework\TestCase;
use yii\base\Event;

final class TrackingProviderRegistryTest extends TestCase
{
    /**
     * Strip global Yii event handlers before AND after every test. Required
     * because `Event::on(...)` attaches to a process-global registry, and a
     * test that throws (like {@see self::testDuplicateHandleThrows()}) skips
     * any cleanup written after the throwing line — leaking handlers into
     * the next test and causing spurious "duplicate handle" failures.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Event::offAll();
    }

    protected function tearDown(): void
    {
        Event::offAll();
        parent::tearDown();
    }

    public function testFiresEventAndCollectsProviders(): void
    {
        Event::on(
            TrackingProviderRegistry::class,
            TrackingProviderRegistry::EVENT_REGISTER_PROVIDERS,
            function(RegisterTrackingProvidersEvent $event): void {
                $event->providers[] = new GA4Provider();
                $event->providers[] = new CustomScriptProvider();
            }
        );

        $registry = new TrackingProviderRegistry();

        $this->assertSame('ga4', $registry->get('ga4')->getHandle());
        $this->assertSame('custom', $registry->get('custom')->getHandle());
        $this->assertNull($registry->get('nonexistent'));
    }

    public function testDuplicateHandleThrows(): void
    {
        Event::on(
            TrackingProviderRegistry::class,
            TrackingProviderRegistry::EVENT_REGISTER_PROVIDERS,
            function(RegisterTrackingProvidersEvent $event): void {
                $event->providers[] = new GA4Provider();
                $event->providers[] = new GA4Provider();
            }
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/duplicate/i');
        new TrackingProviderRegistry();
    }
}
