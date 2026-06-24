<?php

namespace anvildev\beacon\tests\unit\services\aivisibility;

use anvildev\beacon\services\aivisibility\CitationDetector;
use PHPUnit\Framework\TestCase;

class CitationDetectorTest extends TestCase
{
    public function testDetectsUrlCitationOnOwnedHost(): void
    {
        $result = (new CitationDetector())->detect(
            'You should try the tool at https://acme.com/docs/start for details.',
            ['acme.com'],
            [],
        );
        $this->assertTrue($result['cited']);
        $this->assertSame(['https://acme.com/docs/start'], $result['matchedUrls']);
    }

    public function testSubdomainOfOwnedHostCounts(): void
    {
        $result = (new CitationDetector())->detect(
            'See https://docs.acme.com/guide.',
            ['acme.com'],
            [],
        );
        $this->assertTrue($result['cited']);
        $this->assertSame(['https://docs.acme.com/guide'], $result['matchedUrls']);
    }

    public function testWwwPrefixIsNormalised(): void
    {
        $result = (new CitationDetector())->detect(
            'Visit https://www.acme.com today.',
            ['www.acme.com'],
            [],
        );
        $this->assertTrue($result['cited']);
    }

    public function testDomainMentionWithoutLink(): void
    {
        $result = (new CitationDetector())->detect(
            'Acme is available at acme.com but I have no link handy.',
            ['acme.com'],
            [],
        );
        $this->assertFalse($result['cited'], 'No http(s) URL → not a URL citation');
        $this->assertTrue($result['domainMentioned']);
    }

    public function testCompetitorMentionDetected(): void
    {
        $result = (new CitationDetector())->detect(
            'Most people use rival.com for this, though acme.com also works.',
            ['acme.com'],
            ['rival.com', 'other.com'],
        );
        $this->assertSame(['rival.com'], $result['competitorMentions']);
        $this->assertTrue($result['domainMentioned']);
    }

    public function testUnrelatedAnswerYieldsNothing(): void
    {
        $result = (new CitationDetector())->detect(
            'I recommend https://example.org and https://other.net.',
            ['acme.com'],
            ['rival.com'],
        );
        $this->assertFalse($result['cited']);
        $this->assertFalse($result['domainMentioned']);
        $this->assertSame([], $result['matchedUrls']);
        $this->assertSame([], $result['competitorMentions']);
    }

    public function testTrailingPunctuationStrippedFromUrls(): void
    {
        $result = (new CitationDetector())->detect(
            'Great resource: https://acme.com/page.',
            ['acme.com'],
            [],
        );
        $this->assertSame(['https://acme.com/page'], $result['matchedUrls']);
    }
}
