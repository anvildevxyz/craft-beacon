<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\helpers\GeoMarkdownFrontMatter;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the front-matter rendering contract via the extracted helper that
 * {@see \anvildev\beacon\services\GeoMarkdownExportService::buildFrontMatter()}
 * delegates to.
 */
class GeoMarkdownExportServiceFrontMatterTest extends TestCase
{
    public function testEmptyMapProducesNoOutput(): void
    {
        $this->assertSame('', GeoMarkdownFrontMatter::render([]));
    }

    public function testNullAndEmptyStringValuesAreFilteredOut(): void
    {
        $rendered = GeoMarkdownFrontMatter::render([
            'title' => 'Hello',
            'empty' => '',
            'nil' => null,
        ]);
        $this->assertStringContainsString('title: "Hello"', $rendered);
        $this->assertStringNotContainsString('empty', $rendered);
        $this->assertStringNotContainsString('nil', $rendered);
    }

    public function testRenderedYamlIsBracketedByTripleDashes(): void
    {
        $rendered = GeoMarkdownFrontMatter::render(['title' => 'Test']);
        $this->assertStringStartsWith("---\n", $rendered);
        $this->assertStringEndsWith("---\n\n", $rendered);
    }

    public function testValuesAreJsonEncodedForSafety(): void
    {
        $rendered = GeoMarkdownFrontMatter::render(['title' => 'Hello: "world" & friends']);
        $this->assertStringContainsString('Hello: \"world\" & friends', $rendered);
    }

    public function testMultibyteValuesAreNotEscaped(): void
    {
        $rendered = GeoMarkdownFrontMatter::render(['emoji' => '✨']);
        $this->assertStringContainsString('✨', $rendered);
    }

    public function testForwardSlashesInUrlsArePreservedReadably(): void
    {
        $rendered = GeoMarkdownFrontMatter::render(['canonical' => 'https://example.com/foo']);
        $this->assertStringContainsString('https://example.com/foo', $rendered);
        $this->assertStringNotContainsString('https:\/\/example.com\/foo', $rendered);
    }
}
