<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\services\MetaResolverService;
use PHPUnit\Framework\TestCase;

class MetaResolverServiceTest extends TestCase
{
    public function testEntryFieldOverridesDefaults(): void
    {
        $service = new MetaResolverService();
        $meta = $service->resolve(
            entryFieldValue: ['title' => 'Custom Entry Title', 'description' => 'Entry desc'],
            entryTitle: 'Original Title',
            siteName: 'My Site',
            geoDefaults: [],
        );

        $this->assertSame('Custom Entry Title', $meta->title);
        $this->assertSame('Entry desc', $meta->description);
    }

    public function testFallsBackToEntryTitle(): void
    {
        $service = new MetaResolverService();
        $meta = $service->resolve(
            entryFieldValue: ['title' => null, 'description' => null],
            entryTitle: 'Entry T',
            siteName: 'Site',
            geoDefaults: ['titleTemplate' => '{title} | {siteName}'],
        );

        $this->assertSame('Entry T | Site', $meta->title);
    }

    public function testFallsBackToGlobalDescriptionTemplate(): void
    {
        $service = new MetaResolverService();
        $meta = $service->resolve(
            entryFieldValue: ['description' => ''],
            entryTitle: 'Entry T',
            siteName: 'Site',
            geoDefaults: ['descriptionTemplate' => 'About {title} on {siteName}.'],
        );

        $this->assertSame('About Entry T on Site.', $meta->description);
    }

    public function testAiUsageDefaultAllowEmitsNoTokens(): void
    {
        $service = new MetaResolverService();
        $meta = $service->resolve(
            entryFieldValue: [],
            entryTitle: 'X',
            siteName: 'S',
            geoDefaults: [],
        );

        $this->assertSame('allow', $meta->aiUsagePolicy);
        $this->assertNotContains('noai', $meta->robots);
        $this->assertNotContains('noimageai', $meta->robots);
    }

    public function testAiUsageGlobalPolicyAddsRobotsTokens(): void
    {
        $service = new MetaResolverService();
        $meta = $service->resolve(
            entryFieldValue: [],
            entryTitle: 'X',
            siteName: 'S',
            geoDefaults: ['aiUsagePolicy' => 'no-train'],
        );

        $this->assertSame('no-train', $meta->aiUsagePolicy);
        $this->assertContains('noai', $meta->robots);
        $this->assertContains('noimageai', $meta->robots);
    }

    public function testAiUsageEntryOverrideBeatsGlobal(): void
    {
        $service = new MetaResolverService();
        $meta = $service->resolve(
            entryFieldValue: ['aiUsage' => 'allow'],
            entryTitle: 'X',
            siteName: 'S',
            geoDefaults: ['aiUsagePolicy' => 'no-ai'],
        );

        $this->assertSame('allow', $meta->aiUsagePolicy);
        $this->assertNotContains('noai', $meta->robots);
    }

    public function testAiUsageGenerativePolicyAddsOnlyNoai(): void
    {
        $service = new MetaResolverService();
        $meta = $service->resolve(
            entryFieldValue: ['aiUsage' => 'no-generative-ai'],
            entryTitle: 'X',
            siteName: 'S',
            geoDefaults: [],
        );

        $this->assertSame('no-generative-ai', $meta->aiUsagePolicy);
        $this->assertContains('noai', $meta->robots);
        $this->assertNotContains('noimageai', $meta->robots);
    }

    public function testRobotsFlagsAggregate(): void
    {
        $service = new MetaResolverService();
        $meta = $service->resolve(
            entryFieldValue: ['robots' => ['noindex' => true, 'nofollow' => false, 'noarchive' => false, 'nosnippet' => true]],
            entryTitle: 'X',
            siteName: 'S',
            geoDefaults: [],
        );

        $this->assertSame(['noindex', 'nosnippet'], $meta->robots);
    }

    public function testRobotsValueBearingDirectivesFormatToTokens(): void
    {
        $service = new MetaResolverService();
        $meta = $service->resolve(
            entryFieldValue: ['robots' => [
                'noindex' => false,
                'max-snippet' => '160',
                'max-image-preview' => 'large',
                'max-video-preview' => '-1',
                'unavailable_after' => '2027-01-01T00:00:00Z',
            ]],
            entryTitle: 'X',
            siteName: 'S',
            geoDefaults: [],
        );

        $this->assertSame([
            'max-snippet:160',
            'max-image-preview:large',
            'max-video-preview:-1',
            'unavailable_after:2027-01-01T00:00:00Z',
        ], $meta->robots);
    }

    public function testRobotsBlankValueBearingDirectivesAreOmitted(): void
    {
        $service = new MetaResolverService();
        $meta = $service->resolve(
            entryFieldValue: ['robots' => [
                'noindex' => true,
                'max-snippet' => '',
                'max-image-preview' => '',
                'unavailable_after' => '',
            ]],
            entryTitle: 'X',
            siteName: 'S',
            geoDefaults: [],
        );

        $this->assertSame(['noindex'], $meta->robots);
    }

    public function testRobotsEnumOnlyAcceptsKnownOptions(): void
    {
        $service = new MetaResolverService();
        $meta = $service->resolve(
            entryFieldValue: ['robots' => ['max-image-preview' => 'huge']],
            entryTitle: 'X',
            siteName: 'S',
            geoDefaults: [],
        );

        $this->assertSame([], $meta->robots);
    }

    public function testBuildsDefaultOpenGraphAndTwitterFromResolvedValues(): void
    {
        $service = new MetaResolverService();
        $meta = $service->resolve(
            entryFieldValue: [
                'description' => 'Search snippet',
                'canonical' => 'https://example.test/post',
            ],
            entryTitle: 'Post Title',
            siteName: 'Beacon Site',
            geoDefaults: ['titleTemplate' => '{title} | {siteName}'],
        );

        $this->assertSame([
            'title' => 'Post Title | Beacon Site',
            'description' => 'Search snippet',
            'image' => null,
            'type' => 'website',
            'siteName' => 'Beacon Site',
            'url' => 'https://example.test/post',
            'imageWidth' => null,
            'imageHeight' => null,
            'imageAlt' => null,
            'locale' => null,
        ], $meta->openGraph);

        $this->assertSame([
            'card' => 'summary',
            'title' => 'Post Title | Beacon Site',
            'description' => 'Search snippet',
            'image' => null,
            'site' => null,
            'creator' => null,
        ], $meta->twitter);
    }

    public function testFieldSocialOverridesTakePrecedence(): void
    {
        $service = new MetaResolverService();
        $meta = $service->resolve(
            entryFieldValue: [
                'title' => 'Entry Level Title',
                'description' => 'Entry description',
                'openGraph' => ['type' => 'article', 'title' => 'OG Title'],
                'twitter' => ['card' => 'summary_large_image', 'title' => 'X title'],
            ],
            entryTitle: 'Fallback Title',
            siteName: 'Beacon Site',
            geoDefaults: [],
        );

        $this->assertSame('article', $meta->openGraph['type'] ?? null);
        $this->assertSame('OG Title', $meta->openGraph['title'] ?? null);
        $this->assertSame('summary_large_image', $meta->twitter['card'] ?? null);
        $this->assertSame('X title', $meta->twitter['title'] ?? null);
    }

    public function testBundleSchemaMarksArticleOgType(): void
    {
        $service = new MetaResolverService();
        $meta = $service->resolve(
            entryFieldValue: ['description' => 'd'],
            entryTitle: 'Post',
            siteName: 'Site',
            geoDefaults: ['titleTemplate' => '{title}', 'defaultOgType' => 'website'],
            entryUrl: 'https://example.test/p',
            entry: null,
            bundleSchemaTypes: ['WebPage', 'Article'],
        );

        $this->assertSame('article', $meta->openGraph['type'] ?? null);
    }

    public function testDefaultTwitterSiteAddsAtSymbol(): void
    {
        $service = new MetaResolverService();
        $meta = $service->resolve(
            entryFieldValue: ['description' => 'd'],
            entryTitle: 'Post',
            siteName: 'Site',
            geoDefaults: [
                'titleTemplate' => '{title}',
                'defaultTwitterSite' => 'beaconBrand',
            ],
            entryUrl: 'https://example.test/p',
        );

        $this->assertSame('@beaconBrand', $meta->twitter['site'] ?? null);
    }

    public function testOpenGraphImageOverrideMayClearValue(): void
    {
        $service = new MetaResolverService();
        $meta = $service->resolve(
            entryFieldValue: [
                'description' => 'd',
                'ogImage' => 'https://example.test/hero.jpg',
                'openGraph' => ['image' => null],
            ],
            entryTitle: 'Post',
            siteName: 'Site',
            geoDefaults: ['titleTemplate' => '{title}'],
            entryUrl: 'https://example.test/p',
        );

        $this->assertNull($meta->openGraph['image'] ?? null);
    }

    public function testSocialImageSwitchesTwitterCard(): void
    {
        $service = new MetaResolverService();
        $meta = $service->resolve(
            entryFieldValue: [
                'description' => 'd',
                'ogImage' => 'https://example.test/hero.jpg',
            ],
            entryTitle: 'Post',
            siteName: 'Site',
            geoDefaults: [
                'titleTemplate' => '{title}',
                'defaultTwitterCard' => 'summary',
                'defaultTwitterCardWithImage' => 'summary_large_image',
            ],
            entryUrl: 'https://example.test/p',
        );

        $this->assertSame('summary_large_image', $meta->twitter['card'] ?? null);
    }

    public function testSocialImageUsesAbsoluteUrlForOpenGraphAndTwitter(): void
    {
        $service = new MetaResolverService();
        $meta = $service->resolve(
            entryFieldValue: [
                'description' => 'd',
                'ogImage' => 'https://cdn.example.test/images/hero.jpg',
            ],
            entryTitle: 'Post',
            siteName: 'Site',
            geoDefaults: [
                'titleTemplate' => '{title}',
                'defaultTwitterCard' => 'summary',
                'defaultTwitterCardWithImage' => 'summary_large_image',
            ],
            entryUrl: 'https://example.test/p',
        );

        $this->assertSame('https://cdn.example.test/images/hero.jpg', $meta->openGraph['image'] ?? null);
        $this->assertSame('https://cdn.example.test/images/hero.jpg', $meta->twitter['image'] ?? null);
    }
}
