<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\models\SeoMeta;
use anvildev\beacon\variables\BeaconVariable;
use craft\test\TestCase;
use ReflectionMethod;

/**
 * Pagination-aware canonical and hreflang rewriting.
 *
 * Regression cover: alternates must follow the canonical's page when
 * `setPagination({ canonicalMode: 'self' })` is in effect, or canonical says
 * page-N while every language alternate points at page-1 — conflicting signals
 * that Google flags.
 *
 * These live in the integration suite, not the unit suite, because
 * `UrlHelper::urlWithParams()` reads `pathParam` off the general config as of
 * Craft 5.10, so the code path now needs a booted application.
 */
final class BeaconVariablePaginationTest extends TestCase
{
    public function testPage2AppendsPageParam(): void
    {
        $alternates = [
            ['hreflang' => 'en', 'href' => 'https://example.com/blog'],
            ['hreflang' => 'de', 'href' => 'https://example.de/blog'],
        ];

        $rewritten = BeaconVariable::pageAlternates($alternates, 'page', 2);

        $this->assertSame('https://example.com/blog?page=2', $rewritten[0]['href']);
        $this->assertSame('https://example.de/blog?page=2', $rewritten[1]['href']);
        $this->assertSame('en', $rewritten[0]['hreflang']);
        $this->assertSame('de', $rewritten[1]['hreflang']);
    }

    public function testCustomPageParam(): void
    {
        $alternates = [
            ['hreflang' => 'en', 'href' => 'https://example.com/blog'],
        ];

        $rewritten = BeaconVariable::pageAlternates($alternates, 'p', 3);

        $this->assertSame('https://example.com/blog?p=3', $rewritten[0]['href']);
    }

    public function testPageAlternatesRewritesHrefForLaterPages(): void
    {
        $alternates = [
            ['hreflang' => 'en', 'href' => 'https://example.test/blog'],
            ['hreflang' => 'de', 'href' => 'https://example.test/de/blog'],
        ];

        $result = BeaconVariable::pageAlternates($alternates, 'page', 3);

        $this->assertSame('en', $result[0]['hreflang']);
        $this->assertStringContainsString('page=3', $result[0]['href']);
        $this->assertStringContainsString('page=3', $result[1]['href']);
    }

    public function testApplyPaginationToMetaBuildsPrevNextAndSelfCanonical(): void
    {
        $variable = new BeaconVariable();
        $variable->setPagination([
            'page' => 2,
            'pageCount' => 4,
            'baseUrl' => 'https://example.test/blog',
            'canonicalMode' => 'self',
        ]);
        $meta = new SeoMeta();

        $this->invoke($variable, 'applyPaginationToMeta', [$meta]);

        $this->assertStringContainsString('page=2', (string) $meta->canonical);
        $rels = array_column($meta->paginationLinkTags, 'rel');
        $this->assertContains('prev', $rels);
        $this->assertContains('next', $rels);
    }

    public function testApplyPaginationToMetaFirstPageCanonicalKeepsBaseUrl(): void
    {
        $variable = new BeaconVariable();
        $variable->setPagination([
            'page' => 3,
            'pageCount' => 5,
            'baseUrl' => 'https://example.test/blog',
            'canonicalMode' => 'firstPageCanonical',
        ]);
        $meta = new SeoMeta();

        $this->invoke($variable, 'applyPaginationToMeta', [$meta]);

        $this->assertSame('https://example.test/blog', $meta->canonical);
        $rels = array_column($meta->paginationLinkTags, 'rel');
        $this->assertContains('prev', $rels);
        $this->assertContains('next', $rels);
    }

    /**
     * @param list<mixed> $args
     */
    private function invoke(object $obj, string $method, array $args): mixed
    {
        $ref = new ReflectionMethod($obj, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($obj, $args);
    }
}
