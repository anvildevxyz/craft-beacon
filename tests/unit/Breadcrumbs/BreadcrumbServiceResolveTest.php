<?php

namespace anvildev\beacon\tests\unit\Breadcrumbs;

use anvildev\beacon\models\BreadcrumbSettings;
use anvildev\beacon\services\BreadcrumbService;
use PHPUnit\Framework\TestCase;

final class BreadcrumbServiceResolveTest extends TestCase
{
    public function testReturnsEmptyWhenDisabled(): void
    {
        $service = new BreadcrumbService();
        $items = $service->resolve(
            entry: null,
            settings: new BreadcrumbSettings(siteId: 1, enabled: false),
            siteBaseUrl: 'https://example.com/',
        );
        $this->assertSame([], $items);
    }

    public function testReturnsHomeOnlyWhenEntryIsNull(): void
    {
        $service = new BreadcrumbService();
        $items = $service->resolve(
            entry: null,
            settings: new BreadcrumbSettings(siteId: 1, enabled: true, homeLabel: 'Home'),
            siteBaseUrl: 'https://example.com/',
        );
        $this->assertSame([
            ['name' => 'Home', 'url' => 'https://example.com/'],
        ], $items);
    }

    public function testGetResolvedCachesPerEntry(): void
    {
        
        
        
        $service = new BreadcrumbService();
        $settings = new BreadcrumbSettings(siteId: 1, enabled: true, homeLabel: 'Home');

        $override = [
            ['name' => 'Override', 'url' => 'https://example.com/o'],
        ];
        $service->setOverride($override);

        
        $first = $service->getResolved(null, $settings, 'https://example.com/');
        $this->assertSame($override, $first);

        
        
        
        
        $this->assertCount(1, $first);
    }

    public function testOverrideTakesPrecedence(): void
    {
        $service = new BreadcrumbService();
        $override = [
            ['name' => 'Custom Home', 'url' => 'https://example.com/'],
            ['name' => 'Custom Page'],
        ];
        $items = $service->resolve(
            entry: null,
            settings: new BreadcrumbSettings(siteId: 1, enabled: false),
            siteBaseUrl: 'https://example.com/',
            override: $override,
        );
        $this->assertSame($override, $items);
    }

    public function testOverrideSkipsInvalidItemsSilently(): void
    {
        $service = new BreadcrumbService();
        $override = [
            ['name' => 'Valid'],
            ['url' => 'https://example.com/no-name'],   
            'not-an-array',                              
            ['name' => 'AlsoValid', 'url' => 'https://example.com/v'],
        ];
        $items = $service->resolve(
            entry: null,
            settings: new BreadcrumbSettings(siteId: 1, enabled: true),
            siteBaseUrl: 'https://example.com/',
            // @phpstan-ignore-next-line argument.type — deliberately malformed input under test
            override: $override,
        );
        $this->assertSame([
            ['name' => 'Valid'],
            ['name' => 'AlsoValid', 'url' => 'https://example.com/v'],
        ], $items);
    }
}
