<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\services\SitemapService;
use PHPUnit\Framework\TestCase;

class SitemapServiceTest extends TestCase
{
    public function testRendersUrlsetXml(): void
    {
        $service = new SitemapService();
        $entries = [
            ['url' => 'https://example.com/a', 'lastmod' => '2026-04-30T12:00:00+00:00'],
            ['url' => 'https://example.com/b', 'lastmod' => '2026-04-29T08:00:00+00:00'],
        ];
        $xml = $service->renderUrlset($entries, 0.8, 'weekly');

        $this->assertStringStartsWith('<?xml version="1.0"', $xml);
        $this->assertStringContainsString('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $xml);
        $this->assertStringContainsString('<loc>https://example.com/a</loc>', $xml);
        $this->assertStringContainsString('<lastmod>2026-04-30T12:00:00+00:00</lastmod>', $xml);
        $this->assertStringContainsString('<changefreq>weekly</changefreq>', $xml);
        $this->assertStringContainsString('<priority>0.8</priority>', $xml);
        $this->assertStringContainsString('</urlset>', $xml);
    }

    public function testRendersEmptyUrlsetWhenNoEntries(): void
    {
        $service = new SitemapService();
        $xml = $service->renderUrlset([], 0.5, 'weekly');
        $this->assertStringContainsString('<urlset', $xml);
        $this->assertStringContainsString('</urlset>', $xml);
        $this->assertStringNotContainsString('<url>', $xml);
    }

    public function testRendersSitemapIndex(): void
    {
        $service = new SitemapService();
        $sitemaps = [
            ['url' => 'https://example.com/sitemap-news.xml', 'lastmod' => '2026-04-30T12:00:00+00:00'],
            ['url' => 'https://example.com/sitemap-pages.xml', 'lastmod' => '2026-04-29T08:00:00+00:00'],
        ];
        $xml = $service->renderIndex($sitemaps);

        $this->assertStringContainsString('<sitemapindex', $xml);
        $this->assertStringContainsString('<loc>https://example.com/sitemap-news.xml</loc>', $xml);
        $this->assertStringContainsString('</sitemapindex>', $xml);
    }

    public function testEscapesUrls(): void
    {
        $service = new SitemapService();
        $entries = [['url' => 'https://example.com/?q=a&b=c', 'lastmod' => '2026-04-30T12:00:00+00:00']];
        $xml = $service->renderUrlset($entries, 0.5, 'weekly');
        $this->assertStringContainsString('<loc>https://example.com/?q=a&amp;b=c</loc>', $xml);
    }

    public function testRenderUrlsetUsesPerRowPriorityAndChangefreq(): void
    {
        $service = new SitemapService();
        $rows = [[
            'url' => 'https://example.com/p',
            'lastmod' => '2026-04-30T12:00:00+00:00',
            'priority' => 0.2,
            'changefreq' => 'daily',
        ]];
        $xml = $service->renderUrlset($rows, 0.9, 'weekly');
        $this->assertStringContainsString('<priority>0.2</priority>', $xml);
        $this->assertStringContainsString('<changefreq>daily</changefreq>', $xml);
    }

    public function testMergeCoreAndExtrasPreservesCorePerRowPriorityAndChangefreq(): void
    {
        $service = new SitemapService();
        $core = [
            ['url' => 'https://example.com/page', 'lastmod' => '2026-04-02T00:00:00+00:00', 'priority' => 0.2, 'changefreq' => 'daily'],
        ];
        $merged = $service->mergeCoreAndExtras($core, [], 0.9, 'weekly');
        $this->assertCount(1, $merged);
        $this->assertSame(0.2, $merged[0]['priority']);
        $this->assertSame('daily', $merged[0]['changefreq']);
    }

    public function testMergeCoreAndExtrasSortsByUrlAndExtrasOverrideCore(): void
    {
        $service = new SitemapService();
        $core = [
            ['url' => 'https://example.com/z', 'lastmod' => '2026-04-01T00:00:00+00:00'],
            ['url' => 'https://example.com/a', 'lastmod' => '2026-04-02T00:00:00+00:00'],
        ];
        $extras = [
            ['loc' => 'https://example.com/a', 'lastmod' => '2026-05-01T00:00:00+00:00', 'priority' => 0.3],
        ];
        $merged = $service->mergeCoreAndExtras($core, $extras, 0.8, 'weekly');
        $this->assertCount(2, $merged);
        $this->assertSame('https://example.com/a', $merged[0]['url']);
        $this->assertSame('2026-05-01T00:00:00+00:00', $merged[0]['lastmod']);
        $this->assertSame(0.3, $merged[0]['priority']);
        $this->assertSame('https://example.com/z', $merged[1]['url']);
    }

    public function testChunkRowsSplitsByMax(): void
    {
        $service = new SitemapService();
        $rows = [
            ['url' => 'https://example.com/1', 'lastmod' => '2026-04-01T00:00:00+00:00', 'priority' => 0.5, 'changefreq' => 'weekly'],
            ['url' => 'https://example.com/2', 'lastmod' => '2026-04-02T00:00:00+00:00', 'priority' => 0.5, 'changefreq' => 'weekly'],
            ['url' => 'https://example.com/3', 'lastmod' => '2026-04-03T00:00:00+00:00', 'priority' => 0.5, 'changefreq' => 'weekly'],
        ];
        $chunks = $service->chunkRows($rows, 2);
        $this->assertCount(2, $chunks);
        $this->assertCount(2, $chunks[0]);
        $this->assertCount(1, $chunks[1]);
    }

    public function testEffectiveMaxUrlsPerFileClamps(): void
    {
        $service = new SitemapService();
        $this->assertSame(50000, $service->effectiveMaxUrlsPerFile(null));
        $this->assertSame(50000, $service->effectiveMaxUrlsPerFile(0));
        $this->assertSame(50000, $service->effectiveMaxUrlsPerFile(120000));
        $this->assertSame(500, $service->effectiveMaxUrlsPerFile(500));
    }
}
