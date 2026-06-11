<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\services\MetaResolverService;
use craft\elements\Asset;
use craft\errors\ImageTransformException;
use PHPUnit\Framework\TestCase;

/**
 * Locks in the README contract: when `socialImageTransform` is set to a handle
 * that Craft doesn't know about, Beacon falls back to the native asset URL
 * (rather than letting Craft's `ImageTransformException` bubble out and 500
 * the page).
 */
class MetaResolverTransformFallbackTest extends TestCase
{
    public function testNullTransformReturnsNativeUrl(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->expects($this->once())->method('getUrl')
            ->with()
            ->willReturn('https://example.test/native.jpg');

        $result = MetaResolverService::resolveAssetUrlWithTransform($asset, null);

        $this->assertSame('https://example.test/native.jpg', $result['url']);
        $this->assertFalse($result['transformResolved']);
    }

    public function testEmptyTransformReturnsNativeUrl(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->expects($this->once())->method('getUrl')
            ->with()
            ->willReturn('https://example.test/native.jpg');

        $result = MetaResolverService::resolveAssetUrlWithTransform($asset, '');

        $this->assertSame('https://example.test/native.jpg', $result['url']);
        $this->assertFalse($result['transformResolved']);
    }

    public function testKnownTransformReturnsTransformedUrl(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->expects($this->once())->method('getUrl')
            ->with('beaconSocial')
            ->willReturn('https://example.test/transformed/beaconSocial.jpg');

        $result = MetaResolverService::resolveAssetUrlWithTransform($asset, 'beaconSocial');

        $this->assertSame('https://example.test/transformed/beaconSocial.jpg', $result['url']);
        $this->assertTrue($result['transformResolved']);
    }

    public function testUnknownTransformFallsBackSilently(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getUrl')->willReturnCallback(
            function(mixed $transform = null): string {
                if ($transform === null) {
                    return 'https://example.test/native.jpg';
                }
                throw new ImageTransformException('Invalid transform handle: ' . $transform);
            },
        );

        $result = MetaResolverService::resolveAssetUrlWithTransform($asset, 'doesNotExist');

        $this->assertSame('https://example.test/native.jpg', $result['url'], 'unknown transform must fall back to native URL');
        $this->assertFalse($result['transformResolved'], 'fallback path must report transformResolved=false');
    }
}
