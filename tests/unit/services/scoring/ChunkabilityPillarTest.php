<?php

namespace anvildev\beacon\tests\unit\services\scoring;

use anvildev\beacon\enums\GeoScorePillar;
use anvildev\beacon\models\GeoPillarScore;
use anvildev\beacon\services\scoring\ChunkabilityPillar;
use anvildev\beacon\services\scoring\ContentNode;
use anvildev\beacon\services\scoring\PillarContext;
use craft\base\ElementInterface;
use PHPUnit\Framework\TestCase;

class ChunkabilityPillarTest extends TestCase
{
    public function testLeadParagraphInRangeScoresTop(): void
    {
        $ast = [
            $this->heading(2, 'Section one'),
            $this->paragraph(str_repeat('word ', 50)), // 50 words — in [40,75]
            $this->heading(2, 'Section two'),
            $this->paragraph(str_repeat('word ', 60)), // 60 words — in range
        ];
        $score = (new ChunkabilityPillar())->compute($this->ctxWithAst($ast));

        $this->assertSame(GeoScorePillar::Chunkability, $score->pillar);
        $this->assertSame(10, $score->score);
        $this->assertSame(GeoPillarScore::BAND_TOP, $score->band);
        $this->assertSame([], $score->notes);
    }

    public function testLeadParagraphTooShortPenalisesAndNamesSection(): void
    {
        $ast = [
            $this->heading(2, 'Setup'),
            $this->paragraph(str_repeat('word ', 12)), // 12 words — too short
            $this->heading(2, 'Usage'),
            $this->paragraph(str_repeat('word ', 50)), // 50 words — in range
        ];
        $score = (new ChunkabilityPillar())->compute($this->ctxWithAst($ast));

        // 1 of 2 sections in range → 5/10.
        $this->assertSame(5, $score->score);
        $this->assertNotSame([], $score->notes);
        $this->assertStringContainsString('geo.pillar.chunkability.short.lead', $score->notes[0]);
        /** @var list<array{heading: string}> $shortLeads */
        $shortLeads = $score->debug['shortLeads'];
        $this->assertSame('Setup', $shortLeads[0]['heading']);
    }

    public function testLeadParagraphTooLongPenalisesAndNamesSection(): void
    {
        $ast = [
            $this->heading(2, 'Reference'),
            $this->paragraph(str_repeat('word ', 200)), // 200 words — way over
        ];
        $score = (new ChunkabilityPillar())->compute($this->ctxWithAst($ast));

        $this->assertSame(0, $score->score);
        $this->assertNotEmpty($score->notes);
        $this->assertStringContainsString('geo.pillar.chunkability.long.lead', $score->notes[0]);
        /** @var list<array{heading: string}> $longLeads */
        $longLeads = $score->debug['longLeads'];
        $this->assertSame('Reference', $longLeads[0]['heading']);
    }

    public function testStackedSubheadingsAreFlaggedAsHavingNoLead(): void
    {
        $ast = [
            $this->heading(2, 'Configuration'),
            $this->heading(3, 'Database'), // stacked — no lead under H2
            $this->paragraph(str_repeat('word ', 50)),
            $this->heading(2, 'Other'),
            $this->paragraph(str_repeat('word ', 50)),
        ];
        $score = (new ChunkabilityPillar())->compute($this->ctxWithAst($ast));

        // 1 of 2 H2 sections has a proper lead → 5/10.
        $this->assertSame(5, $score->score);
        $this->assertStringContainsString('geo.pillar.chunkability.stacked.headings', $score->notes[0]);
        /** @var list<string> $stackedHeadings */
        $stackedHeadings = $score->debug['stackedHeadings'];
        $this->assertContains('Configuration', $stackedHeadings);
    }

    public function testNoLeadAtAllPenalises(): void
    {
        $ast = [
            $this->heading(2, 'Empty section'),
            // nothing after the H2
        ];
        $score = (new ChunkabilityPillar())->compute($this->ctxWithAst($ast));

        $this->assertSame(0, $score->score);
        $this->assertStringContainsString('geo.pillar.chunkability.stacked.headings', $score->notes[0]);
        /** @var list<string> $stackedHeadings */
        $stackedHeadings = $score->debug['stackedHeadings'];
        $this->assertContains('Empty section', $stackedHeadings);
    }

    public function testNoH2SectionsGivesDirectionalLowBand(): void
    {
        // An H3-only entry has no H2 sections, so the pillar can't score.
        // It returns a directional low band, not a hard fail.
        $ast = [
            $this->heading(3, 'A subsection'),
            $this->paragraph('Body text.'),
        ];
        $score = (new ChunkabilityPillar())->compute($this->ctxWithAst($ast));

        $this->assertSame(3, $score->score);
        $this->assertSame(GeoPillarScore::BAND_LOW, $score->band);
        $this->assertNotEmpty($score->notes);
    }

    public function testListsAndTablesAreNotCountedAsLeadParagraphs(): void
    {
        $ast = [
            $this->heading(2, 'Reference'),
            new ContentNode(
                type: ContentNode::TYPE_LIST,
                text: 'Apple Pear Plum',
                wordCount: 3,
                items: ['Apple', 'Pear', 'Plum'],
            ),
            $this->paragraph(str_repeat('word ', 50)), // the actual lead arrives after the list
        ];
        $score = (new ChunkabilityPillar())->compute($this->ctxWithAst($ast));

        // The 50-word paragraph counts as the lead because lists are
        // skipped — AI engines quote prose, not bullets.
        $this->assertSame(10, $score->score);
    }

    private function heading(int $level, string $text): ContentNode
    {
        return new ContentNode(
            type: ContentNode::TYPE_HEADING,
            level: $level,
            text: $text,
            wordCount: ContentNode::countWords($text),
        );
    }

    private function paragraph(string $text): ContentNode
    {
        return new ContentNode(
            type: ContentNode::TYPE_PARAGRAPH,
            text: trim($text),
            wordCount: ContentNode::countWords($text),
        );
    }

    /**
     * Build a PillarContext that returns the supplied AST without needing
     * a Craft bootstrap. The element + siteId are unused by chunkability —
     * the pillar reads purely from `$ctx->ast()` — so a stub element is fine.
     *
     * @param list<ContentNode> $ast
     */
    private function ctxWithAst(array $ast): PillarContext
    {
        $element = $this->createStub(ElementInterface::class);
        $ctx = new PillarContext($element, 1);
        // PillarContext memoises the first `ast()` call. Pre-seed the cache
        // via reflection so the pillar never invokes ContentWalker.
        $reflection = new \ReflectionProperty($ctx, 'ast');
        $reflection->setAccessible(true);
        $reflection->setValue($ctx, $ast);
        return $ctx;
    }
}
