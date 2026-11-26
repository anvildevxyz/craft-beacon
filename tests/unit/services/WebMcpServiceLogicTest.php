<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\services\WebMcpService;
use craft\models\Site;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pure-logic coverage for {@see WebMcpService}: the URL-to-URI resolver, the
 * search/page DTO builders, and the served script's templating. These avoid
 * the DB-touching search()/page()/sections() orchestration methods, which
 * need a booted Craft app.
 */
class WebMcpServiceLogicTest extends TestCase
{
    public function testUriFromUrlStripsSchemeHostAndBasePath(): void
    {
        $service = $this->service();
        $site = $this->site('https://example.test/shop');

        $this->assertSame(
            'blog/my-post',
            $this->invoke($service, 'uriFromUrl', ['https://example.test/shop/blog/my-post', $site]),
        );
    }

    public function testUriFromUrlAcceptsABarePathWithoutSchemeOrHost(): void
    {
        $service = $this->service();
        $site = $this->site('https://example.test');

        $this->assertSame('blog/my-post', $this->invoke($service, 'uriFromUrl', ['/blog/my-post', $site]));
    }

    public function testUriFromUrlFallsBackToHomeConventionForTheSiteRoot(): void
    {
        $service = $this->service();
        $site = $this->site('https://example.test/shop');

        $this->assertSame('__home__', $this->invoke($service, 'uriFromUrl', ['https://example.test/shop', $site]));
        $this->assertSame('__home__', $this->invoke($service, 'uriFromUrl', ['https://example.test/shop/', $site]));
        $this->assertSame('__home__', $this->invoke($service, 'uriFromUrl', ['', $site]));
    }

    public function testUriFromUrlFallsBackToHomeForAUrlWithNoPathComponentAtAll(): void
    {
        $service = $this->service();
        $site = $this->site('https://example.test');

        // parse_url() returns null (not '') for a bare "scheme://host" URL —
        // must not fall through to using the whole URL string as the path.
        $this->assertSame('__home__', $this->invoke($service, 'uriFromUrl', ['https://example.test', $site]));
    }

    public function testUriFromUrlDoesNotCrashOnAMalformedUrl(): void
    {
        $service = $this->service();
        $site = $this->site('https://example.test');

        // parse_url() returns false (not null) for a malformed URL — `??`
        // alone would not catch this; must not throw or emit a PHP warning.
        $this->assertSame('__home__', $this->invoke($service, 'uriFromUrl', ['http:///', $site]));
    }

    public function testUriFromUrlLeavesAPathAloneWhenItDoesNotShareTheSiteBasePath(): void
    {
        $service = $this->service();
        $site = $this->site('https://example.test/shop');

        // A URL on a different host/path than the site's base — the base-path
        // prefix just doesn't match, so nothing is stripped.
        $this->assertSame(
            'blog/my-post',
            $this->invoke($service, 'uriFromUrl', ['https://other.test/blog/my-post', $site]),
        );
    }

    public function testBuildSearchHitMapsFieldsVerbatim(): void
    {
        $service = $this->service();
        $hit = $this->invoke($service, 'buildSearchHit', ['Title', 'https://example.test/a', 'A snippet.']);

        $this->assertSame(['title' => 'Title', 'url' => 'https://example.test/a', 'snippet' => 'A snippet.'], $hit);
    }

    public function testBuildSearchHitAllowsANullSnippet(): void
    {
        $service = $this->service();
        $hit = $this->invoke($service, 'buildSearchHit', ['Title', 'https://example.test/a', null]);

        $this->assertNull($hit['snippet']);
    }

    public function testBuildPageResultMapsFieldsVerbatim(): void
    {
        $service = $this->service();
        $result = $this->invoke($service, 'buildPageResult', [
            'Title', 'https://example.test/a', 'A description.', '# Title\n\nBody.',
        ]);

        $this->assertSame([
            'title' => 'Title',
            'url' => 'https://example.test/a',
            'description' => 'A description.',
            'markdown' => '# Title\n\nBody.',
        ], $result);
    }

    public function testBuildScriptEmbedsTheThreeEndpointUrls(): void
    {
        $service = $this->service();
        $js = $this->invoke($service, 'buildScript', [
            'https://example.test/beacon/webmcp/search',
            'https://example.test/beacon/webmcp/page',
            'https://example.test/beacon/webmcp/sections',
        ]);

        $this->assertStringContainsString('https://example.test/beacon/webmcp/search', $js);
        $this->assertStringContainsString('https://example.test/beacon/webmcp/page', $js);
        $this->assertStringContainsString('https://example.test/beacon/webmcp/sections', $js);
        $this->assertStringContainsString('window.beaconWebMcp', $js);
        $this->assertStringContainsString('searchContent:', $js);
        $this->assertStringContainsString('getPage:', $js);
        $this->assertStringContainsString('listSections:', $js);
        $this->assertStringContainsString('navigateTo:', $js);
    }

    public function testBuildScriptNavigateToRejectsCrossOriginBeforeAssigning(): void
    {
        $service = $this->service();
        $js = $this->invoke($service, 'buildScript', ['a', 'b', 'c']);

        $this->assertStringContainsString('cross-origin navigation is not allowed', $js);
        $this->assertStringContainsString('target.origin !== window.location.origin', $js);
    }

    private function service(): WebMcpService
    {
        return (new ReflectionClass(WebMcpService::class))->newInstanceWithoutConstructor();
    }

    private function site(string $baseUrl): Site
    {
        $site = (new ReflectionClass(Site::class))->newInstanceWithoutConstructor();
        $site->setBaseUrl($baseUrl);
        return $site;
    }

    /**
     * @param array<int,mixed> $args
     */
    private function invoke(object $obj, string $method, array $args): mixed
    {
        $ref = new ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }
}
