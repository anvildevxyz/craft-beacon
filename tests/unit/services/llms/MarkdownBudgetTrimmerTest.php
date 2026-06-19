<?php

namespace anvildev\beacon\tests\unit\services\llms;

use anvildev\beacon\services\llms\MarkdownBudgetTrimmer;
use anvildev\beacon\services\llms\TokenEstimatorInterface;
use PHPUnit\Framework\TestCase;

class MarkdownBudgetTrimmerTest extends TestCase
{
    /**
     * Deterministic estimator: one token per whitespace-delimited word. Keeps
     * the budget arithmetic in these tests exact and independent of the
     * shipping heuristic.
     */
    private function wordCounter(): TokenEstimatorInterface
    {
        return new class implements TokenEstimatorInterface {
            public function estimate(string $text): int
            {
                $text = trim($text);
                return $text === '' ? 0 : count(preg_split('/\s+/', $text) ?: []);
            }
        };
    }

    private function doc(): string
    {
        // Each "# X\nxxx" section is 3 words under the word-counter.
        return "# A\naaa\n# B\nbbb\n# C\nccc\n";
    }

    public function testZeroBudgetReturnsUnchanged(): void
    {
        $trimmer = new MarkdownBudgetTrimmer($this->wordCounter());
        $result = $trimmer->trim($this->doc(), 0);

        $this->assertSame($this->doc(), $result->markdown);
        $this->assertFalse($result->truncated);
        $this->assertSame(9, $result->originalTokens);
    }

    public function testBudgetAboveContentReturnsUnchanged(): void
    {
        $result = (new MarkdownBudgetTrimmer($this->wordCounter()))->trim($this->doc(), 100);
        $this->assertSame($this->doc(), $result->markdown);
        $this->assertFalse($result->truncated);
    }

    public function testTrimsAtHeadingBoundaryAndStaysWithinBudget(): void
    {
        $result = (new MarkdownBudgetTrimmer($this->wordCounter()))->trim($this->doc(), 6);

        $this->assertTrue($result->truncated);
        $this->assertStringContainsString('# A', $result->markdown);
        $this->assertStringContainsString('# B', $result->markdown);
        $this->assertStringNotContainsString('# C', $result->markdown);
        // Never cut mid-section: the kept body ends exactly at the last full section.
        $this->assertStringEndsWith("bbb\n", $result->markdown);
        $this->assertLessThanOrEqual(6, $result->estimatedTokens);
        $this->assertSame(9, $result->originalTokens);
    }

    public function testFirstSectionAlwaysKeptEvenWhenItExceedsBudget(): void
    {
        $result = (new MarkdownBudgetTrimmer($this->wordCounter()))->trim($this->doc(), 2);

        $this->assertStringContainsString('# A', $result->markdown);
        $this->assertStringNotContainsString('# B', $result->markdown);
        $this->assertTrue($result->truncated);
    }

    public function testDocumentWithoutHeadingsIsServedWholeEvenOverBudget(): void
    {
        $plain = "just some prose with several words and no headings at all\n";
        $result = (new MarkdownBudgetTrimmer($this->wordCounter()))->trim($plain, 2);

        $this->assertSame($plain, $result->markdown);
        $this->assertFalse($result->truncated);
    }

    public function testLeadingContentBeforeFirstHeadingIsPreserved(): void
    {
        $doc = "intro line\n# A\naaa\n# B\nbbb\n# C\nccc\n";
        $result = (new MarkdownBudgetTrimmer($this->wordCounter()))->trim($doc, 5);

        $this->assertStringStartsWith('intro line', $result->markdown);
        $this->assertTrue($result->truncated);
    }

    public function testDefaultEstimatorConstructsWithoutArgument(): void
    {
        $result = (new MarkdownBudgetTrimmer())->trim("# A\nhello world\n", 0);
        $this->assertSame("# A\nhello world\n", $result->markdown);
        $this->assertGreaterThan(0, $result->originalTokens);
    }
}
