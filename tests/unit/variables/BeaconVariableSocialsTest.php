<?php

namespace anvildev\beacon\tests\unit\variables;

use anvildev\beacon\models\Settings;
use anvildev\beacon\Plugin;
use anvildev\beacon\services\SettingsService;
use anvildev\beacon\variables\BeaconVariable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionObject;

/**
 * Covers socials()/socialUrl() row building against configured profiles.
 * Plugin::$plugin is stubbed with a constructor-less instance carrying a
 * settings service whose memo is pre-seeded, so no Craft app is required;
 * tearDown() restores the null Plugin::$plugin the rest of the suite expects.
 */
class BeaconVariableSocialsTest extends TestCase
{
    protected function tearDown(): void
    {
        Plugin::$plugin = null;
        parent::tearDown();
    }

    public function testSocialsBuildsRowsForConfiguredPlatformsOnly(): void
    {
        $this->stubPluginWithProfiles([
            'twitter' => '  https://x.com/acme  ',
            'facebook' => '   ',
            'linkedin' => 'https://linkedin.com/company/acme',
            'notAPlatform' => 'https://example.com/ignored',
        ]);

        $rows = (new BeaconVariable())->socials();

        $this->assertSame([
            [
                'platform' => 'twitter',
                'url' => 'https://x.com/acme',
                'handle' => 'acme',
                'label' => 'X / Twitter',
            ],
            [
                'platform' => 'linkedin',
                'url' => 'https://linkedin.com/company/acme',
                // linkedin has no handle hosts — company URLs carry no bare handle
                'handle' => null,
                'label' => 'LinkedIn',
            ],
        ], $rows);
    }

    public function testSocialUrlReturnsTrimmedUrlOrNull(): void
    {
        $this->stubPluginWithProfiles([
            'twitter' => '  https://x.com/acme  ',
            'facebook' => '   ',
        ]);
        $variable = new BeaconVariable();

        $this->assertSame('https://x.com/acme', $variable->socialUrl('twitter'));
        $this->assertNull($variable->socialUrl('facebook'));
        $this->assertNull($variable->socialUrl('youtube'));
    }

    /**
     * @param array<string,string> $profiles
     */
    private function stubPluginWithProfiles(array $profiles): void
    {
        $service = new SettingsService();
        $cached = (new ReflectionObject($service))->getProperty('cached');
        $cached->setAccessible(true);
        $cached->setValue($service, new Settings(socialProfiles: $profiles));

        $plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
        $plugin->set('settings', $service);
        Plugin::$plugin = $plugin;
    }
}
