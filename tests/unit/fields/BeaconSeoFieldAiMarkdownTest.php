<?php

namespace anvildev\beacon\tests\unit\fields;

use anvildev\beacon\fields\BeaconSeoField;
use craft\base\ElementInterface;
use craft\models\FieldLayout;
use PHPUnit\Framework\TestCase;

/**
 * `readAiMarkdownFor()` extracts and parses the per-element aiMarkdown override
 * group. It must:
 * - default to inherit/empty when the SEO field is not on the layout
 * - parse `customFrontMatter` textareas line-by-line, dropping blanks/malformed
 * - strip balanced quotes around values
 * - clamp `enabled` to one of: inherit, include, exclude
 */
class BeaconSeoFieldAiMarkdownTest extends TestCase
{
    public function testDefaultsWhenFieldAbsent(): void
    {
        $element = $this->mockElementWithSeoValue(null);
        $result = BeaconSeoField::readAiMarkdownFor($element);
        $this->assertSame('inherit', $result->enabled);
        $this->assertSame([], $result->customFrontMatter);
    }

    public function testEnabledTriStateIsClampedToKnownValues(): void
    {
        foreach (['inherit', 'include', 'exclude'] as $value) {
            $element = $this->mockElementWithSeoValue([
                'aiMarkdown' => ['enabled' => $value, 'customFrontMatter' => ''],
            ]);
            $this->assertSame($value, BeaconSeoField::readAiMarkdownFor($element)->enabled);
        }
    }

    public function testInvalidEnabledFallsBackToInherit(): void
    {
        $element = $this->mockElementWithSeoValue([
            'aiMarkdown' => ['enabled' => 'maybe', 'customFrontMatter' => ''],
        ]);
        $this->assertSame('inherit', BeaconSeoField::readAiMarkdownFor($element)->enabled);
    }

    public function testCustomFrontMatterParsesKeyValueLines(): void
    {
        $element = $this->mockElementWithSeoValue([
            'aiMarkdown' => [
                'enabled' => 'inherit',
                'customFrontMatter' => "audience: developers\nlicense: MIT",
            ],
        ]);
        $fm = BeaconSeoField::readAiMarkdownFor($element)->customFrontMatter;
        $this->assertSame(['audience' => 'developers', 'license' => 'MIT'], $fm);
    }

    public function testBlankLinesAreDropped(): void
    {
        $element = $this->mockElementWithSeoValue([
            'aiMarkdown' => [
                'enabled' => 'inherit',
                'customFrontMatter' => "\n\naudience: developers\n\n",
            ],
        ]);
        $fm = BeaconSeoField::readAiMarkdownFor($element)->customFrontMatter;
        $this->assertSame(['audience' => 'developers'], $fm);
    }

    public function testLinesWithoutColonAreDropped(): void
    {
        $element = $this->mockElementWithSeoValue([
            'aiMarkdown' => [
                'enabled' => 'inherit',
                'customFrontMatter' => "this is not a key value\naudience: ok",
            ],
        ]);
        $fm = BeaconSeoField::readAiMarkdownFor($element)->customFrontMatter;
        $this->assertSame(['audience' => 'ok'], $fm);
    }

    public function testDoubleQuotedValuesAreUnwrapped(): void
    {
        $element = $this->mockElementWithSeoValue([
            'aiMarkdown' => [
                'enabled' => 'inherit',
                'customFrontMatter' => 'title: "Hello World"',
            ],
        ]);
        $this->assertSame('Hello World', BeaconSeoField::readAiMarkdownFor($element)->customFrontMatter['title']);
    }

    public function testSingleQuotedValuesAreUnwrapped(): void
    {
        $element = $this->mockElementWithSeoValue([
            'aiMarkdown' => [
                'enabled' => 'inherit',
                'customFrontMatter' => "title: 'Hello World'",
            ],
        ]);
        $this->assertSame('Hello World', BeaconSeoField::readAiMarkdownFor($element)->customFrontMatter['title']);
    }

    public function testUnbalancedQuotesAreNotStripped(): void
    {
        $element = $this->mockElementWithSeoValue([
            'aiMarkdown' => [
                'enabled' => 'inherit',
                'customFrontMatter' => 'title: "Hello',
            ],
        ]);
        $this->assertSame('"Hello', BeaconSeoField::readAiMarkdownFor($element)->customFrontMatter['title']);
    }

    public function testColonInValueIsPreserved(): void
    {
        
        $element = $this->mockElementWithSeoValue([
            'aiMarkdown' => [
                'enabled' => 'inherit',
                'customFrontMatter' => 'url: https://example.com/foo:bar',
            ],
        ]);
        $this->assertSame('https://example.com/foo:bar', BeaconSeoField::readAiMarkdownFor($element)->customFrontMatter['url']);
    }

    public function testEmptyKeyIsDropped(): void
    {
        $element = $this->mockElementWithSeoValue([
            'aiMarkdown' => [
                'enabled' => 'inherit',
                'customFrontMatter' => ": orphan\nvalid: ok",
            ],
        ]);
        $this->assertSame(['valid' => 'ok'], BeaconSeoField::readAiMarkdownFor($element)->customFrontMatter);
    }

    /**
     * Build a minimal Element mock whose layout returns a stub Beacon SEO field
     * and whose `getFieldValue('handle')` returns `$value`.
     *
     * @param array<string,mixed>|null $value
     */
    private function mockElementWithSeoValue(?array $value): ElementInterface
    {
        if ($value === null) {
            
            $element = $this->createMock(ElementInterface::class);
            $element->method('getFieldLayout')->willReturn(null);
            return $element;
        }

        $field = new BeaconSeoField();
        $field->handle = 'seo';

        $layout = $this->createMock(FieldLayout::class);
        $layout->method('getCustomFields')->willReturn([$field]);

        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldLayout')->willReturn($layout);
        $element->method('getFieldValue')->with('seo')->willReturn($value);

        return $element;
    }
}
