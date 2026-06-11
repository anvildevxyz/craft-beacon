<?php

namespace anvildev\beacon\tests\unit\helpers;

use anvildev\beacon\helpers\GeoMarkdownFrontMatter;
use PHPUnit\Framework\TestCase;

class GeoMarkdownFrontMatterTest extends TestCase
{
    public function testMergeLayersLaterWins(): void
    {
        $merged = GeoMarkdownFrontMatter::mergeLayers(
            ['title' => 'Site', 'tone' => 'formal'],
            ['title' => 'Section'],
            ['canonical' => 'https://example.test/x'],
            ['title' => 'Entry override'],
        );

        $this->assertSame([
            'title' => 'Entry override',
            'tone' => 'formal',
            'canonical' => 'https://example.test/x',
        ], $merged);
    }

    public function testEncodeValueReturnsNullForEmpty(): void
    {
        $this->assertNull(GeoMarkdownFrontMatter::encodeValue(null));
        $this->assertNull(GeoMarkdownFrontMatter::encodeValue(''));
    }

    public function testEncodeValueJsonEncodesScalars(): void
    {
        $encoded = GeoMarkdownFrontMatter::encodeValue('Hello: "world"');
        $this->assertSame('"Hello: \"world\""', $encoded);
    }

    public function testRenderProducesBracketedYaml(): void
    {
        $rendered = GeoMarkdownFrontMatter::render([
            'title' => 'Test',
            'empty' => '',
            'nil' => null,
        ]);

        $this->assertStringStartsWith("---\n", $rendered);
        $this->assertStringEndsWith("---\n\n", $rendered);
        $this->assertStringContainsString('title: "Test"', $rendered);
        $this->assertStringNotContainsString('empty', $rendered);
    }

    public function testRenderReturnsEmptyForBlankMap(): void
    {
        $this->assertSame('', GeoMarkdownFrontMatter::render([]));
        $this->assertSame('', GeoMarkdownFrontMatter::render(['x' => '']));
    }
}
