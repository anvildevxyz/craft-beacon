<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\services\SchemaSuggestionService;
use PHPUnit\Framework\TestCase;

class SchemaSuggestionServiceTest extends TestCase
{
    private SchemaSuggestionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SchemaSuggestionService();
    }

    public function testSuggestForTypeMapsRegistryHintsToTokens(): void
    {
        $mapping = $this->service->suggestForType('Article');

        // headline's first hint is `seo.title`; image's is `seo.openGraph.image`.
        $this->assertSame('{seo.title}', $mapping['headline']);
        $this->assertSame('{seo.openGraph.image}', $mapping['image']);
        $this->assertSame('{entry.postDate}', $mapping['datePublished']);
    }

    public function testSuggestForTypeOmitsPropertiesWithoutHints(): void
    {
        $mapping = $this->service->suggestForType('Article');

        // `articleSection` and `keywords` carry no `suggest` hints, so they
        // should not be proposed (authors fill them in by hand).
        $this->assertArrayNotHasKey('articleSection', $mapping);
        $this->assertArrayNotHasKey('keywords', $mapping);
    }

    public function testSuggestForTypeReturnsEmptyForUnknownType(): void
    {
        $this->assertSame([], $this->service->suggestForType('NotARealType'));
    }

    public function testKnowsTypeTracksTheRegistry(): void
    {
        $this->assertTrue($this->service->knowsType('Article'));
        $this->assertFalse($this->service->knowsType('NotARealType'));
    }
}
