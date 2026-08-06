<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\gql\queries\BeaconPublicFilesQueries;
use anvildev\beacon\gql\types\BeaconPublicFilesType;
use anvildev\beacon\Plugin;
use Craft;
use craft\models\Site;
use craft\test\TestCase;
use GraphQL\Error\UserError;

/**
 * Coverage for the public-files GraphQL surface (issue #37): the
 * `beaconFiles` root query and the PublicFilesService renderers it shares
 * with the HTTP controllers (robots.txt, sitemap.xml, llms.txt, …).
 *
 * @group requires-craft
 */
class GqlPublicFilesIntegrationTest extends TestCase
{
    public function testResolveFilesDefaultsToPrimarySite(): void
    {
        $result = BeaconPublicFilesQueries::resolveFiles(null, []);

        $this->assertInstanceOf(Site::class, $result['site']);
        $this->assertSame(Craft::$app->getSites()->getPrimarySite()->id, $result['site']->id);
    }

    public function testResolveFilesRejectsUnknownSite(): void
    {
        $this->expectException(UserError::class);
        BeaconPublicFilesQueries::resolveFiles(null, ['siteId' => 999999]);
    }

    public function testRobotsTxtRendersWithSiteScopedSitemapLine(): void
    {
        $body = Plugin::getInstance()->publicFiles->robotsTxt($this->site());

        // Default settings use sitemapUrl=auto, so the Sitemap line must
        // point at this site's sitemap.xml.
        $this->assertStringContainsString('Sitemap:', $body);
        $this->assertStringContainsString('sitemap.xml', $body);
    }

    public function testSitemapMasterIsAnXmlDocument(): void
    {
        $xml = Plugin::getInstance()->publicFiles->sitemapMaster($this->site());

        $this->assertStringStartsWith('<?xml', $xml);
        $this->assertTrue(
            str_contains($xml, '<urlset') || str_contains($xml, '<sitemapindex'),
            'Master sitemap must be a urlset or a sitemap index',
        );
    }

    public function testSitemapPartOutOfRangeIsNull(): void
    {
        $files = Plugin::getInstance()->publicFiles;

        $this->assertNull($files->sitemapPart($this->site(), 0));
        $this->assertNull($files->sitemapPart($this->site(), 9999));
    }

    public function testOptionalFilesReturnNullOrBody(): void
    {
        // llms/ads/humans depend on per-site settings; regardless of what
        // the fixture DB has configured, the renderers must return a clean
        // null-or-body without throwing (the HTTP routes turn null into 404).
        $files = Plugin::getInstance()->publicFiles;
        $site = $this->site();

        foreach (['llmsTxt', 'adsTxt', 'humansTxt'] as $method) {
            $body = $files->$method($site);
            $this->assertTrue($body === null || (is_string($body) && $body !== ''), "$method must be null or non-empty");
        }

        $full = $files->llmsFull($site);
        $this->assertTrue($full === null || $full->markdown !== '');
    }

    public function testTypeFieldsAndQueryRegistrationAreStable(): void
    {
        $fields = BeaconPublicFilesType::getFieldDefinitions();
        foreach (['robotsTxt', 'sitemap', 'sitemapPart', 'llmsTxt', 'llmsFullTxt', 'adsTxt', 'humansTxt'] as $name) {
            $this->assertArrayHasKey($name, $fields, "Missing field: $name");
        }

        $this->assertSame('BeaconPublicFiles', BeaconPublicFilesType::getName());
        $this->assertArrayHasKey('beaconFiles', BeaconPublicFilesQueries::getQueries(false));
    }

    private function site(): Site
    {
        return Craft::$app->getSites()->getPrimarySite();
    }
}
