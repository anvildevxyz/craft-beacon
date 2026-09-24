<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\Plugin;
use craft\test\TestCase;

/**
 * @group requires-craft
 */
final class SettingsAutoCanonicalPersistenceTest extends TestCase
{
    public function testAutoCanonicalEnabledRoundTripsThroughSaveAndReload(): void
    {
        $plugin = Plugin::getInstance();
        $previous = clone $plugin->settings->get();

        try {
            $enabled = clone $previous;
            $enabled->autoCanonicalEnabled = true;
            $plugin->settings->save($enabled);

            $reloaded = $plugin->settings->get();
            $this->assertTrue($reloaded->autoCanonicalEnabled);
            $this->assertTrue($reloaded->toGeoDefaults()['autoCanonicalEnabled']);
        } finally {
            $plugin->settings->save($previous);
        }

        $this->assertFalse($plugin->settings->get()->autoCanonicalEnabled);
    }
}
