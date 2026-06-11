<?php

namespace anvildev\beacon\tests\unit\services\markdown;

use anvildev\beacon\services\markdown\HtmlChromeStripper;
use PHPUnit\Framework\TestCase;

class HtmlChromeStripperTest extends TestCase
{
    public function testStripYiiBlockMarkersRemovesCdataPlaceholders(): void
    {
        $stripper = new HtmlChromeStripper();
        $html = '<p>before</p><![CDATA[YII-BLOCK-BEGIN-PAGE]]><p>after</p><![CDATA[YII-BLOCK-END]]>';
        $this->assertSame('<p>before</p><p>after</p>', $stripper->stripYiiBlockMarkers($html));
    }

    public function testStripElementsWithClassesRemovesMatchingNodes(): void
    {
        $stripper = new HtmlChromeStripper();
        $html = '<div class="content"><p>keep</p></div><nav class="site-nav">drop</nav>';
        $result = $stripper->stripElementsWithClasses($html, ['site-nav']);
        $this->assertStringContainsString('keep', $result);
        $this->assertStringNotContainsString('drop', $result);
        $this->assertStringNotContainsString('site-nav', $result);
    }

    public function testStripElementsWithClassesIsByteIdenticalWhenNoClassesProvided(): void
    {
        $stripper = new HtmlChromeStripper();
        $html = '<div class="anything"><p>untouched</p></div>';
        $this->assertSame($html, $stripper->stripElementsWithClasses($html, []));
    }

    public function testHtmlToMarkdownConvertsBasicBlocks(): void
    {
        $stripper = new HtmlChromeStripper();
        $md = $stripper->htmlToMarkdown('<h2>Heading</h2><p>Body text.</p>');
        $this->assertStringContainsString('## Heading', $md);
        $this->assertStringContainsString('Body text.', $md);
    }

    public function testFlattenHtmlStripsTagsAndCollapsesWhitespace(): void
    {
        $stripper = new HtmlChromeStripper();
        $result = $stripper->flattenHtml("<p>One</p>\n\n<p>  Two  </p>");
        // Whitespace-collapsed plaintext is the contract; exact spacing
        // depends on StringHelper::collapseWhitespace, so assert both words land.
        $this->assertStringContainsString('One', $result);
        $this->assertStringContainsString('Two', $result);
        $this->assertStringNotContainsString('<p>', $result);
    }

    public function testExtractMarkedContentRemovesDropRegions(): void
    {
        $stripper = new HtmlChromeStripper();
        $html = 'A' . HtmlChromeStripper::MARKER_DROP_START . 'B' . HtmlChromeStripper::MARKER_DROP_END . 'C';
        $this->assertSame('AC', $stripper->extractMarkedContent($html));
    }

    public function testExtractMarkedContentReturnsUnchangedWithoutMarkers(): void
    {
        $stripper = new HtmlChromeStripper();
        $this->assertSame('<p>plain</p>', $stripper->extractMarkedContent('<p>plain</p>'));
    }

    public function testExtractMarkedContentLimitsToKeepRegions(): void
    {
        $stripper = new HtmlChromeStripper();
        $html = 'chrome' . HtmlChromeStripper::MARKER_KEEP_START . 'BODY' . HtmlChromeStripper::MARKER_KEEP_END . 'footer';
        $this->assertSame('BODY', $stripper->extractMarkedContent($html));
    }

    public function testExtractMarkedContentJoinsMultipleKeepRegions(): void
    {
        $stripper = new HtmlChromeStripper();
        $k1 = HtmlChromeStripper::MARKER_KEEP_START . 'one' . HtmlChromeStripper::MARKER_KEEP_END;
        $k2 = HtmlChromeStripper::MARKER_KEEP_START . 'two' . HtmlChromeStripper::MARKER_KEEP_END;
        $this->assertSame("one\n\ntwo", $stripper->extractMarkedContent('x' . $k1 . 'y' . $k2 . 'z'));
    }

    public function testExtractMarkedContentHandlesUnterminatedKeepRegion(): void
    {
        $stripper = new HtmlChromeStripper();
        // Keep-start with no matching end: the `|$` alternation runs to EOS.
        $html = 'chrome' . HtmlChromeStripper::MARKER_KEEP_START . 'tail body';
        $this->assertSame('tail body', $stripper->extractMarkedContent($html));
    }

    public function testExtractMarkedContentDropsRegionsBeforeApplyingKeep(): void
    {
        $stripper = new HtmlChromeStripper();
        $html = HtmlChromeStripper::MARKER_KEEP_START . 'keep'
            . HtmlChromeStripper::MARKER_DROP_START . 'gone' . HtmlChromeStripper::MARKER_DROP_END
            . 'more' . HtmlChromeStripper::MARKER_KEEP_END;
        $this->assertSame('keepmore', $stripper->extractMarkedContent($html));
    }

    public function testStripElementsWithClassesRemovesMultipleClasses(): void
    {
        $stripper = new HtmlChromeStripper();
        $html = '<nav class="nav">n</nav><div class="content">keep</div><footer class="ft">f</footer>';
        $result = $stripper->stripElementsWithClasses($html, ['nav', 'ft']);
        $this->assertStringContainsString('keep', $result);
        $this->assertStringNotContainsString('>n<', $result);
        $this->assertStringNotContainsString('>f<', $result);
    }

    public function testHtmlToMarkdownCollapsesExcessBlankLines(): void
    {
        $stripper = new HtmlChromeStripper();
        $md = $stripper->htmlToMarkdown('<p>A</p><p>B</p><p>C</p>');
        $this->assertStringNotContainsString("\n\n\n", $md);
    }
}
