<?php

namespace anvildev\beacon\tests\unit\helpers\links;

use anvildev\beacon\helpers\links\VolumeUrlResolver;
use PHPUnit\Framework\TestCase;

class VolumeUrlResolverTest extends TestCase
{
    public function testBuildPrefixMapNormalisesTrailingSlashes(): void
    {
        $map = VolumeUrlResolver::buildPrefixMap([
            ['id' => 1, 'baseUrl' => 'https://cdn.example.com/images/'],
            ['id' => 2, 'baseUrl' => 'https://cdn.example.com/docs'],
        ]);
        $this->assertArrayHasKey('https://cdn.example.com/images', $map);
        $this->assertArrayHasKey('https://cdn.example.com/docs', $map);
        $this->assertSame(1, $map['https://cdn.example.com/images']);
        $this->assertSame(2, $map['https://cdn.example.com/docs']);
    }

    public function testBuildPrefixMapSkipsEmptyBaseUrls(): void
    {
        $map = VolumeUrlResolver::buildPrefixMap([
            ['id' => 1, 'baseUrl' => ''],
            ['id' => 2, 'baseUrl' => 'https://cdn.example.com/docs'],
        ]);
        $this->assertCount(1, $map);
    }

    public function testBuildPrefixMapSortsByLengthDescending(): void
    {
        $map = VolumeUrlResolver::buildPrefixMap([
            ['id' => 1, 'baseUrl' => 'https://cdn.example.com/'],
            ['id' => 2, 'baseUrl' => 'https://cdn.example.com/images/'],
        ]);
        $keys = array_keys($map);
        // Longest prefix must come first for correct greedy matching
        $this->assertSame('https://cdn.example.com/images', $keys[0]);
    }

    public function testMatchPrefixReturnsVolumeIdAndPath(): void
    {
        $map = ['https://cdn.example.com/images' => 1];
        $result = VolumeUrlResolver::matchPrefix('https://cdn.example.com/images/2026/hero.jpg', $map);
        $this->assertSame(['volumeId' => 1, 'path' => '2026/hero.jpg'], $result);
    }

    public function testMatchPrefixReturnsNullWhenNoMatch(): void
    {
        $map = ['https://cdn.example.com/images' => 1];
        $this->assertNull(VolumeUrlResolver::matchPrefix('https://other.example.com/foo.jpg', $map));
    }

    public function testMatchPrefixPrefersLongestMatch(): void
    {
        $map = [
            'https://cdn.example.com/images' => 2,
            'https://cdn.example.com' => 1,
        ];
        $result = VolumeUrlResolver::matchPrefix('https://cdn.example.com/images/hero.jpg', $map);
        $this->assertSame(2, $result['volumeId']);
    }

    public function testMatchPrefixStripsQueryString(): void
    {
        $map = ['https://cdn.example.com/images' => 1];
        $result = VolumeUrlResolver::matchPrefix('https://cdn.example.com/images/hero.jpg?v=123', $map);
        $this->assertSame('hero.jpg', $result['path']);
    }

    public function testStripTransformSegmentRemovesDimensionsFormat(): void
    {
        $this->assertSame(
            '2026/hero.jpg',
            VolumeUrlResolver::stripTransformSegment('2026/_1024x768_crop_center-center/hero.jpg')
        );
    }

    public function testStripTransformSegmentRemovesAutoFormat(): void
    {
        $this->assertSame(
            '2026/hero.jpg',
            VolumeUrlResolver::stripTransformSegment('2026/_1024xAUTO_crop_center-center/hero.jpg')
        );
    }

    public function testStripTransformSegmentRemovesNamedTransform(): void
    {
        $this->assertSame(
            'uploads/photo.png',
            VolumeUrlResolver::stripTransformSegment('uploads/_heroThumbnail/photo.png')
        );
    }

    public function testStripTransformSegmentPreservesPathWithoutTransform(): void
    {
        $this->assertSame('2026/hero.jpg', VolumeUrlResolver::stripTransformSegment('2026/hero.jpg'));
    }

    public function testStripTransformSegmentPreservesUnderscoreInFilename(): void
    {
        $this->assertSame('2026/my_photo.jpg', VolumeUrlResolver::stripTransformSegment('2026/my_photo.jpg'));
    }
}
