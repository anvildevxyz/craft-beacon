<?php

namespace anvildev\beacon\tests\unit\services\scoring;

use anvildev\beacon\enums\GeoScorePillar;
use anvildev\beacon\models\GeoPillarScore;
use anvildev\beacon\services\scoring\AuthorityDomainRegistry;
use anvildev\beacon\services\scoring\ContentNode;
use anvildev\beacon\services\scoring\heuristics\DomainMatcher;
use anvildev\beacon\services\scoring\OutboundCitationDensityPillar;
use anvildev\beacon\services\scoring\PillarContext;
use craft\base\ElementInterface;
use PHPUnit\Framework\TestCase;

class OutboundCitationDensityPillarTest extends TestCase
{
    public function testZeroOutboundLinksScoresZero(): void
    {
        $ast = [$this->paragraph(str_repeat('lorem ipsum ', 50))]; // 100 words
        $score = $this->pillar()->compute($this->ctx($ast));

        $this->assertSame(GeoScorePillar::OutboundCitationDensity, $score->pillar);
        $this->assertSame(0, $score->score);
        $this->assertSame(GeoPillarScore::BAND_STALE, $score->band);
        $this->assertNotEmpty($score->notes);
        $this->assertStringContainsString('No outbound citations', $score->notes[0]);
    }

    public function testInternalLinksAreIgnored(): void
    {
        $ast = [
            $this->paragraph(str_repeat('lorem ipsum ', 50)),
            $this->link('https://example.com/internal', true),
            $this->link('https://example.com/another', true),
        ];
        $score = $this->pillar()->compute($this->ctx($ast));

        // No outbound links → score 0.
        $this->assertSame(0, $score->score);
    }

    public function testTier1WeightedHigherThanTier2(): void
    {
        // 1000 words, 1 tier-1 link → raw = 1.0 → per1k = 1.0 → score = 2
        $ast1 = array_merge(
            $this->prose(1000),
            [$this->link('https://en.wikipedia.org/wiki/X', false)],
        );
        // 1000 words, 1 tier-2 link → raw = 0.6 → per1k = 0.6 → score = round(0.6*2) = 1
        $ast2 = array_merge(
            $this->prose(1000),
            [$this->link('https://nytimes.com/article', false)],
        );
        $tier1Score = $this->pillar()->compute($this->ctx($ast1));
        $tier2Score = $this->pillar()->compute($this->ctx($ast2));

        $this->assertGreaterThan($tier2Score->score, $tier1Score->score);
        $this->assertSame(1, $tier1Score->debug['tier1']);
        $this->assertSame(1, $tier2Score->debug['tier2']);
    }

    public function testWildcardDomainMatch(): void
    {
        // de.wikipedia.org should match the bundled *.wikipedia.org tier-1 entry.
        $ast = array_merge(
            $this->prose(1000),
            [$this->link('https://de.wikipedia.org/wiki/Y', false)],
        );
        $score = $this->pillar()->compute($this->ctx($ast));
        $this->assertSame(1, $score->debug['tier1']);
    }

    public function testHighDensityHitsTopBand(): void
    {
        // 1000 words + 5 tier-1 + 5 tier-2 → raw = 5 + 3 = 8 → clamped to 5 → score 10.
        $links = [];
        for ($i = 0; $i < 5; $i++) {
            $links[] = $this->link("https://en.wikipedia.org/wiki/A{$i}", false);
            $links[] = $this->link("https://nytimes.com/{$i}", false);
        }
        $ast = array_merge($this->prose(1000), $links);
        $score = $this->pillar()->compute($this->ctx($ast));

        $this->assertSame(10, $score->score);
        $this->assertSame(GeoPillarScore::BAND_TOP, $score->band);
        $this->assertSame([], $score->notes);
    }

    public function testUnclassifiedHostsAppearInNotes(): void
    {
        $ast = array_merge(
            $this->prose(800),
            [
                $this->link('https://en.wikipedia.org/wiki/Z', false),
                $this->link('https://example-blog.com/post', false),
                $this->link('https://random-blog.com/post', false),
            ],
        );
        $score = $this->pillar()->compute($this->ctx($ast));

        $this->assertSame(2, $score->debug['unclassified']);
        $combined = implode("\n", $score->notes);
        $this->assertStringContainsString('example-blog.com', $combined);
    }

    public function testTooShortContentScoresBottomBand(): void
    {
        $ast = [$this->paragraph('Two short paragraphs only.')];
        $score = $this->pillar()->compute($this->ctx($ast));

        $this->assertSame(1, $score->score);
        $this->assertStringContainsString('too short', $score->notes[0]);
    }

    private function pillar(): OutboundCitationDensityPillar
    {
        $reg = new AuthorityDomainRegistry(
            new DomainMatcher(),
            dirname(__DIR__, 4) . '/src/data/authority-domains.json',
            [], // no operator overrides — defaults only
        );
        return new OutboundCitationDensityPillar($reg, new DomainMatcher());
    }

    private function paragraph(string $text): ContentNode
    {
        return new ContentNode(
            type: ContentNode::TYPE_PARAGRAPH,
            text: $text,
            wordCount: ContentNode::countWords($text),
        );
    }

    private function link(string $href, bool $isInternal): ContentNode
    {
        return new ContentNode(
            type: ContentNode::TYPE_LINK,
            href: $href,
            isInternal: $isInternal,
        );
    }

    /**
     * @return list<ContentNode>
     */
    private function prose(int $words): array
    {
        return [$this->paragraph(str_repeat('lorem ', $words))];
    }

    /**
     * @param list<ContentNode> $ast
     */
    private function ctx(array $ast): PillarContext
    {
        $element = $this->createStub(ElementInterface::class);
        $ctx = new PillarContext($element, 1);
        $reflection = new \ReflectionProperty($ctx, 'ast');
        $reflection->setAccessible(true);
        $reflection->setValue($ctx, $ast);
        return $ctx;
    }
}
