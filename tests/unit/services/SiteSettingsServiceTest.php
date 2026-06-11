<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\services\SiteSettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionObject;

class SiteSettingsServiceTest extends TestCase
{
    public function testParseUserAgentRules(): void
    {
        $svc = new SiteSettingsService();
        $rules = $this->invoke($svc, 'parseUserAgentRules', ['[{"userAgent":"*","disallow":["/admin/"]}]']);
        $this->assertCount(1, $rules);
        $this->assertSame('*', $rules[0]['userAgent']);
    }

    public function testDecodeSectionSitemapClampsPriorityAndFiltersInvalidChangefreq(): void
    {
        $svc = new SiteSettingsService();
        $json = json_encode([
            'blog' => ['priority' => 1.5, 'changefreq' => 'weekly'],
            'news' => ['priority' => -0.2, 'changefreq' => 'bogus'],
            'docs' => ['priority' => 'nope'],
            '' => ['priority' => 0.5],
            42 => ['priority' => 0.5],
        ], JSON_THROW_ON_ERROR);

        $decoded = $this->invoke($svc, 'decodeSectionSitemap', [$json]);

        $this->assertSame(1.0, $decoded['blog']['priority']);
        $this->assertSame('weekly', $decoded['blog']['changefreq']);
        $this->assertSame(0.0, $decoded['news']['priority']);
        $this->assertArrayNotHasKey('changefreq', $decoded['news']);
        $this->assertArrayNotHasKey('docs', $decoded);
        $this->assertArrayNotHasKey('', $decoded);
    }

    public function testDecodeSectionSitemapReturnsEmptyForBlankJson(): void
    {
        $svc = new SiteSettingsService();
        $this->assertSame([], $this->invoke($svc, 'decodeSectionSitemap', ['{}']));
        $this->assertSame([], $this->invoke($svc, 'decodeSectionSitemap', ['[]']));
        $this->assertSame([], $this->invoke($svc, 'decodeSectionSitemap', ['']));
        $this->assertSame([], $this->invoke($svc, 'decodeSectionSitemap', ['not-json']));
    }

    public function testDecodeGeoMarkdownFrontMatterKeepsScalarsAndDropsComplexValues(): void
    {
        $svc = new SiteSettingsService();
        $json = json_encode([
            'blog' => [
                'title' => 'Blog',
                'enabled' => true,
                'count' => 3,
                'nil' => null,
                'nested' => ['drop' => 'me'],
                '' => 'skip',
            ],
            'news' => 'not-an-array',
        ], JSON_THROW_ON_ERROR);

        $decoded = $this->invoke($svc, 'decodeGeoMarkdownFrontMatter', [$json]);

        $this->assertSame([
            'title' => 'Blog',
            'enabled' => true,
            'count' => 3,
            'nil' => null,
        ], $decoded['blog']);
        $this->assertArrayNotHasKey('news', $decoded);
    }

    public function testDecodeHandleMapDropsRowsThatMapToEmptyArrays(): void
    {
        $svc = new SiteSettingsService();
        $json = json_encode([
            'empty' => ['priority' => 'nope'],
            'valid' => ['priority' => 0.4, 'changefreq' => 'daily'],
        ], JSON_THROW_ON_ERROR);

        $decoded = $this->invoke($svc, 'decodeSectionSitemap', [$json]);

        $this->assertArrayNotHasKey('empty', $decoded);
        $this->assertSame(0.4, $decoded['valid']['priority']);
        $this->assertSame('daily', $decoded['valid']['changefreq']);
    }

    public function testCacheKeyAndInvalidationAreIsolatedPerKindAndSite(): void
    {
        
        
        
        
        
        
        $svc = new SiteSettingsService();
        $cacheKey = $this->invoke($svc, 'cacheKey', ['llms', 1]);
        $this->assertSame('llms:1', $cacheKey);

        $different = $this->invoke($svc, 'cacheKey', ['robots', 1]);
        $this->assertSame('robots:1', $different);

        $crossSite = $this->invoke($svc, 'cacheKey', ['llms', 2]);
        $this->assertSame('llms:2', $crossSite);

        
        $ref = new ReflectionObject($svc);
        $prop = $ref->getProperty('cache');
        $prop->setAccessible(true);
        $prop->setValue($svc, [
            'llms:1' => 'A',
            'llms:2' => 'B',
            'robots:1' => 'C',
        ]);

        $this->invoke($svc, 'invalidate', ['llms', 1]);

        $after = $prop->getValue($svc);
        $this->assertArrayNotHasKey('llms:1', $after);
        $this->assertSame('B', $after['llms:2'], 'sibling site key untouched');
        $this->assertSame('C', $after['robots:1'], 'sibling kind key untouched');
    }

    /** @param array<int,mixed> $args */
    private function invoke(object $obj, string $method, array $args): mixed
    {
        $ref = new ReflectionObject($obj);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($obj, $args);
    }
}
