<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\models\Settings;
use anvildev\beacon\services\SettingsService;
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

    /**
     * Regression: a per-section AI-usage policy must survive the decode that
     * runs on every settings read. It used to be stripped (only title/description
     * templates were preserved), silently disabling section-level no-train/no-ai.
     */
    public function testDecodeSectionSeoDefaultsPreservesAiUsage(): void
    {
        $json = (string) json_encode([
            'blog' => ['titleTemplate' => '{title}', 'descriptionTemplate' => '', 'aiUsage' => 'no-train'],
            'docs' => ['aiUsage' => 'no-ai'], // aiUsage-only row, no SEO templates
            'news' => ['titleTemplate' => '{title}'], // no policy
        ]);

        $method = new \ReflectionMethod(SettingsService::class, 'decodeSectionSeoDefaults');
        $method->setAccessible(true);
        /** @var array<string,array<string,string>> $decoded */
        $decoded = $method->invoke(new SettingsService(), $json);

        // Policy survives alongside templates...
        $this->assertSame('no-train', $decoded['blog']['aiUsage'] ?? null);
        // ...and an aiUsage-only section is NOT dropped.
        $this->assertArrayHasKey('docs', $decoded);
        $this->assertSame('no-ai', $decoded['docs']['aiUsage'] ?? null);
        // Sections without a policy don't gain the key.
        $this->assertArrayNotHasKey('aiUsage', $decoded['news']);
    }

}
