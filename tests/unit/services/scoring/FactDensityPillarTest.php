<?php

namespace anvildev\beacon\tests\unit\services\scoring;

use anvildev\beacon\enums\GeoScorePillar;
use anvildev\beacon\models\GeoPillarScore;
use anvildev\beacon\services\scoring\ContentNode;
use anvildev\beacon\services\scoring\FactDensityPillar;
use anvildev\beacon\services\scoring\heuristics\FactDetectors;
use anvildev\beacon\services\scoring\PillarContext;
use craft\base\ElementInterface;
use PHPUnit\Framework\TestCase;

class FactDensityPillarTest extends TestCase
{
    public function testTopBandWhenDensityAtTarget(): void
    {
        // 5 numeric facts in 400 words = 1:80 = exactly at default target.
        $paragraph = $this->paragraph(
            $this->wordsWithFacts(400, 5),
        );
        $score = $this->pillar(80)->compute($this->ctxWithAst([$paragraph]));

        $this->assertSame(GeoScorePillar::FactDensity, $score->pillar);
        $this->assertSame(10, $score->score);
        $this->assertSame(GeoPillarScore::BAND_TOP, $score->band);
        $this->assertSame([], $score->notes);
    }

    public function testLowBandWhenDensityFarBelowTarget(): void
    {
        // 1 fact in 400 words = 1:400, far below 1:80 target.
        $paragraph = $this->paragraph($this->wordsWithFacts(400, 1));
        $score = $this->pillar(80)->compute($this->ctxWithAst([$paragraph]));

        $this->assertSame(2, $score->score);
        $this->assertSame(GeoPillarScore::BAND_LOW, $score->band);
        $this->assertNotEmpty($score->notes);
        $this->assertStringContainsString('Add', $score->notes[0]);
    }

    public function testTooShortContentScoresBottomBandWithDistinctNote(): void
    {
        // < 50 words: pillar refuses to score and emits a "too short" note
        // rather than reporting a misleading 0 density.
        $paragraph = $this->paragraph($this->wordsWithFacts(20, 0));
        $score = $this->pillar(80)->compute($this->ctxWithAst([$paragraph]));

        $this->assertSame(1, $score->score);
        $this->assertStringContainsString('too short', $score->notes[0]);
    }

    public function testEmptyAstScoresZeroWithDistinctNote(): void
    {
        $score = $this->pillar(80)->compute($this->ctxWithAst([]));

        $this->assertSame(0, $score->score);
        $this->assertStringContainsString('No content', $score->notes[0]);
    }

    public function testTargetSettingShiftsThreshold(): void
    {
        // Same content, two targets — at target=160 the same density that
        // misses 1:80 should now hit a higher band.
        // 2 facts in 320 words = 1:160 — exactly at the softened target.
        $paragraph = $this->paragraph($this->wordsWithFacts(320, 2));

        $strict = $this->pillar(80)->compute($this->ctxWithAst([$paragraph]));
        $softened = $this->pillar(160)->compute($this->ctxWithAst([$paragraph]));

        // Same content, looser target → strictly higher score.
        $this->assertGreaterThan($strict->score, $softened->score);
        $this->assertSame(10, $softened->score);
    }

    public function testCitationLinksContributeToDensity(): void
    {
        // 250-word paragraph + 4 outbound citation links → ~1:60 effective
        // density even though the paragraph itself has no numeric facts.
        $paragraph = $this->paragraph(str_repeat('Lorem ipsum dolor sit amet ', 50)); // 250 words of pure prose
        $links = [
            new ContentNode(type: ContentNode::TYPE_LINK, href: 'https://example.com/a', isInternal: false),
            new ContentNode(type: ContentNode::TYPE_LINK, href: 'https://example.com/b', isInternal: false),
            new ContentNode(type: ContentNode::TYPE_LINK, href: 'https://other.org/c', isInternal: false),
            new ContentNode(type: ContentNode::TYPE_LINK, href: 'https://example.com/internal', isInternal: true), // ignored
        ];
        $score = $this->pillar(80)->compute($this->ctxWithAst([$paragraph, ...$links]));

        // 3 outbound facts in 250 words = density 0.012, ratio 0.012 × 80 = 0.96 → 10.
        $this->assertGreaterThanOrEqual(8, $score->score);
    }

    /**
     * Generate a string of $totalWords words, salted with $factCount easily
     * detectable numeric assertions (percentages — the cheapest detector
     * pattern). Used to dial in known density without depending on the
     * specific shape of any other detector.
     */
    private function wordsWithFacts(int $totalWords, int $factCount): string
    {
        $proseWords = max(0, $totalWords - ($factCount * 2)); // each fact phrase is ~2 words
        $prose = str_repeat('lorem ', $proseWords);
        $facts = '';
        for ($i = 1; $i <= $factCount; $i++) {
            $facts .= "metric{$i} rose " . (10 + $i * 3) . "%. ";
        }
        return trim($prose . ' ' . $facts);
    }

    private function paragraph(string $text): ContentNode
    {
        return new ContentNode(
            type: ContentNode::TYPE_PARAGRAPH,
            text: $text,
            wordCount: ContentNode::countWords($text),
        );
    }

    /**
     * @param list<ContentNode> $ast
     */
    private function ctxWithAst(array $ast): PillarContext
    {
        $element = $this->createStub(ElementInterface::class);
        $ctx = new PillarContext($element, 1);
        $reflection = new \ReflectionProperty($ctx, 'ast');
        $reflection->setAccessible(true);
        $reflection->setValue($ctx, $ast);
        return $ctx;
    }

    private function pillar(int $target): FactDensityPillar
    {
        return new FactDensityPillar(new FactDetectors(), $target);
    }
}
