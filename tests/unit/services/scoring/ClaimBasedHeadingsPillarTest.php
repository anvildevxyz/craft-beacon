<?php

namespace anvildev\beacon\tests\unit\services\scoring;

use anvildev\beacon\enums\GeoScorePillar;
use anvildev\beacon\models\GeoPillarScore;
use anvildev\beacon\services\scoring\ClaimBasedHeadingsPillar;
use anvildev\beacon\services\scoring\ContentNode;
use anvildev\beacon\services\scoring\PillarContext;
use craft\base\ElementInterface;
use craft\models\Site;
use PHPUnit\Framework\TestCase;

class ClaimBasedHeadingsPillarTest extends TestCase
{
    public function testAllClaimShapedHeadingsScoreTop(): void
    {
        $ast = [
            $this->heading(2, 'Composer plugins must run before PHP-FPM restarts'),
            $this->heading(2, 'Async jobs reduce save-time latency to zero'),
            $this->heading(3, 'Beacon ships GEO scoring in 3.1'),
        ];
        $score = (new ClaimBasedHeadingsPillar())->compute($this->ctx($ast, 'en'));

        $this->assertSame(GeoScorePillar::ClaimBasedHeadings, $score->pillar);
        $this->assertSame(10, $score->score);
        $this->assertSame(GeoPillarScore::BAND_TOP, $score->band);
        $this->assertSame([], $score->notes);
    }

    public function testTopicHeadingsScoreLowAndNoteNamesOffenders(): void
    {
        $ast = [
            $this->heading(2, 'Composer plugins'),
            $this->heading(2, 'GEO scoring'),
            $this->heading(2, 'Installation guide'),
        ];
        $score = (new ClaimBasedHeadingsPillar())->compute($this->ctx($ast, 'en'));

        $this->assertSame(0, $score->score);
        $this->assertNotEmpty($score->notes);
        $combined = implode("\n", $score->notes);
        $this->assertStringContainsString('Composer plugins', $combined);
        $this->assertStringContainsString('GEO scoring', $combined);
    }

    public function testMixedHeadingsScoreInProportion(): void
    {
        $ast = [
            $this->heading(2, 'Composer plugins must run before PHP-FPM restarts'), // claim
            $this->heading(2, 'Async jobs reduce save-time latency to zero'),       // claim
            $this->heading(2, 'Beacon ships GEO scoring in 3.1'),                   // claim
            $this->heading(2, 'Installation'),                                       // topic
        ];
        $score = (new ClaimBasedHeadingsPillar())->compute($this->ctx($ast, 'en'));

        // 3 of 4 → 7.5 → rounds to 8.
        $this->assertSame(8, $score->score);
        // The lone topic heading is named in the note so authors know
        // exactly which one to rewrite.
        $this->assertStringContainsString('Installation', implode("\n", $score->notes));
    }

    public function testGermanSiteLanguageUsesGermanVerbStems(): void
    {
        $ast = [
            $this->heading(2, 'Composer Plugins müssen vor PHP-FPM laufen'),
            $this->heading(2, 'Asynchrone Jobs reduzieren die Speicherlatenz'),
        ];
        $score = (new ClaimBasedHeadingsPillar())->compute($this->ctx($ast, 'de'));
        $this->assertSame(10, $score->score);

        // Same AST, English language tag → no English verbs in the
        // German headings, so they all score as topics.
        $englishScore = (new ClaimBasedHeadingsPillar())->compute($this->ctx($ast, 'en'));
        $this->assertSame(0, $englishScore->score);
    }

    public function testEntryWithNoH2OrH3GivesDirectionalLowBand(): void
    {
        // H1-only / no-heading entries get a low band, not a zero —
        // short content (stubs, press releases) shouldn't get fully
        // penalised for lacking subheadings.
        $ast = [
            $this->heading(1, 'Page title'),
            new ContentNode(type: ContentNode::TYPE_PARAGRAPH, text: 'A body paragraph.', wordCount: 3),
        ];
        $score = (new ClaimBasedHeadingsPillar())->compute($this->ctx($ast, 'en'));

        $this->assertSame(3, $score->score);
        $this->assertSame(GeoPillarScore::BAND_LOW, $score->band);
        $this->assertNotEmpty($score->notes);
    }

    public function testH4AndDeeperAreIgnoredFromClaimRatio(): void
    {
        // Only H2/H3 count — H4+ are too deeply nested to carry
        // self-contained answers an AI engine would quote.
        $ast = [
            $this->heading(2, 'A claim-shaped section that runs long enough'),
            $this->heading(4, 'A topic phrase'),       // ignored
            $this->heading(5, 'Another topic phrase'), // ignored
        ];
        $score = (new ClaimBasedHeadingsPillar())->compute($this->ctx($ast, 'en'));

        $this->assertSame(10, $score->score);
    }

    /**
     * @param list<ContentNode> $ast
     */
    private function ctx(array $ast, string $language): PillarContext
    {
        // Real Site instead of a stub — PHPUnit stubs don't reliably
        // expose mutated public properties through Yii's __get hook,
        // and constructing a Site directly is cheap.
        $site = new Site(['language' => $language]);
        $element = $this->createStub(ElementInterface::class);
        $element->method('getSite')->willReturn($site);

        $ctx = new PillarContext($element, 1);
        $reflection = new \ReflectionProperty($ctx, 'ast');
        $reflection->setAccessible(true);
        $reflection->setValue($ctx, $ast);
        return $ctx;
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
}
