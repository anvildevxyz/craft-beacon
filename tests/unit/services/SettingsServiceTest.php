<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\models\Settings;
use PHPUnit\Framework\TestCase;

class SettingsServiceTest extends TestCase
{
    public function testToGeoDefaultsShape(): void
    {
        $settings = new Settings(
            titleTemplate: '{title} | {siteName}',
            organizationName: 'Anvil Dev',
            socialProfiles: ['twitter' => 'https://x.com/anvil'],
        );

        $geo = $settings->toGeoDefaults();

        $this->assertSame('{title} | {siteName}', $geo['titleTemplate']);
        $this->assertSame('Anvil Dev', $geo['organization']['name']);
        $this->assertSame(['https://x.com/anvil'], $geo['organization']['sameAs']);
        $this->assertSame('beaconSocial', $geo['socialImageTransform']);
        // defaultTwitterSite is derived from socialProfiles['twitter'].
        $this->assertSame('anvil', $geo['defaultTwitterSite']);
        // OG type and Twitter card type are no longer in geoDefaults — they're
        // hardcoded in MetaResolverService.
        $this->assertArrayNotHasKey('defaultOgType', $geo);
        $this->assertArrayNotHasKey('defaultTwitterCard', $geo);
        $this->assertArrayNotHasKey('defaultTwitterCardWithImage', $geo);
        $this->assertArrayNotHasKey('geoMarkdownSectionAllowlist', $geo);
    }

    public function testBehaviorDefaultsAndRoundTrip(): void
    {
        $defaults = new Settings();
        $this->assertSame(90, $defaults->staleThresholdDays);
        $this->assertSame(30, $defaults->botLogRetentionDays);
        $this->assertSame([], $defaults->geoMarkdownSectionAllowlist);
        $this->assertNull($defaults->geoMarkdownExcerptLength);
        $this->assertTrue($defaults->geoMarkdownExcerptFallbackToDescription);

        $custom = new Settings(
            staleThresholdDays: 14,
            botLogRetentionDays: 7,
        );
        $this->assertSame(14, $custom->staleThresholdDays);
        $this->assertSame(7, $custom->botLogRetentionDays);
    }

    public function testGeoMarkdownPolicyConstructorValuesRoundTrip(): void
    {
        $settings = new Settings(
            geoMarkdownSectionAllowlist: ['blog', 'docs'],
            geoMarkdownExcerptLength: 600,
            geoMarkdownExcerptFallbackToDescription: false,
        );

        $this->assertSame(['blog', 'docs'], $settings->geoMarkdownSectionAllowlist);
        $this->assertSame(600, $settings->geoMarkdownExcerptLength);
        $this->assertFalse($settings->geoMarkdownExcerptFallbackToDescription);
    }

}
