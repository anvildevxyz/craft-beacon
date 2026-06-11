<?php

namespace anvildev\beacon\tests\unit\Breadcrumbs;

use anvildev\beacon\services\BreadcrumbService;
use PHPUnit\Framework\TestCase;

final class BreadcrumbServiceJsonLdTest extends TestCase
{
    public function testEmptyArrayReturnsNull(): void
    {
        $this->assertNull((new BreadcrumbService())->asJsonLd([]));
    }

    public function testThreeItemChainProducesCorrectShape(): void
    {
        $jsonLd = (new BreadcrumbService())->asJsonLd([
            ['name' => 'Home', 'url' => 'https://example.com/'],
            ['name' => 'Blog', 'url' => 'https://example.com/blog'],
            ['name' => 'Post Title'],
        ]);

        $this->assertSame([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://example.com/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Blog', 'item' => 'https://example.com/blog'],
                ['@type' => 'ListItem', 'position' => 3, 'name' => 'Post Title'],
            ],
        ], $jsonLd);
    }

    public function testItemsWithoutUrlEmitListItemsWithoutItem(): void
    {
        $jsonLd = (new BreadcrumbService())->asJsonLd([
            ['name' => 'Home', 'url' => 'https://example.com/'],
            ['name' => 'Disabled Ancestor'], 
            ['name' => 'Current Page'],
        ]);

        $this->assertArrayNotHasKey('item', $jsonLd['itemListElement'][1]);
        $this->assertArrayNotHasKey('item', $jsonLd['itemListElement'][2]);
    }

    public function testMatchesSnapshot(): void
    {
        $jsonLd = (new BreadcrumbService())->asJsonLd([
            ['name' => 'Home', 'url' => 'https://example.com/'],
            ['name' => 'Blog', 'url' => 'https://example.com/blog'],
            ['name' => 'Post Title'],
        ]);

        $snapshot = json_decode((string) file_get_contents(__DIR__ . '/../../snapshots/breadcrumbs/three-item-chain.json'), true);
        $this->assertSame($snapshot, $jsonLd);
    }
}
