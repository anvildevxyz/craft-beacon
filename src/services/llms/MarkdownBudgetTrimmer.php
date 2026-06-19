<?php

namespace anvildev\beacon\services\llms;

/**
 * Trims a Markdown document to a token budget by dropping trailing
 * heading-delimited sections. Boundaries are ATX headings (`#`…`######`), so a
 * trim never lands inside a sentence — a consumer always receives whole
 * sections. This mirrors the chunk boundaries the GEO content score rewards
 * (claim-based headings / chunkability), so the budgeted `llms-full.txt`
 * carries the same self-contained units the score is built around.
 *
 * The document's leading content (anything before the first heading) plus the
 * first section are always kept, so output is never empty even when that first
 * unit alone exceeds the budget — the byte ceiling enforced at save time is the
 * hard backstop in that (rare) case.
 */
final class MarkdownBudgetTrimmer
{
    public function __construct(
        private readonly TokenEstimatorInterface $estimator = new HeuristicTokenEstimator(),
    ) {
    }

    /**
     * @param int $budgetTokens Token ceiling. 0 (or negative) disables trimming.
     */
    public function trim(string $markdown, int $budgetTokens): TokenBudgetResult
    {
        $originalTokens = $this->estimator->estimate($markdown);

        if ($budgetTokens <= 0 || $originalTokens <= $budgetTokens) {
            return new TokenBudgetResult($markdown, $originalTokens, $originalTokens, false);
        }

        $segments = $this->splitIntoSections($markdown);
        if (count($segments) <= 1) {
            // Nothing to drop without cutting mid-section; serve as-is.
            return new TokenBudgetResult($markdown, $originalTokens, $originalTokens, false);
        }

        $kept = '';
        $keptCount = 0;
        foreach ($segments as $segment) {
            $candidate = $kept === '' ? $segment : $kept . $segment;
            // Always keep the first segment, even if it alone exceeds the budget.
            if ($keptCount > 0 && $this->estimator->estimate($candidate) > $budgetTokens) {
                break;
            }
            $kept = $candidate;
            $keptCount++;
        }

        $kept = rtrim($kept) . "\n";

        return new TokenBudgetResult(
            $kept,
            $this->estimator->estimate($kept),
            $originalTokens,
            $keptCount < count($segments),
        );
    }

    /**
     * Splits Markdown into segments that each begin at an ATX heading. Content
     * before the first heading becomes the leading segment. Every segment
     * retains its original trailing newlines so re-concatenation is lossless.
     *
     * @return list<string>
     */
    private function splitIntoSections(string $markdown): array
    {
        $lines = explode("\n", $markdown);
        $segments = [];
        $current = '';

        foreach ($lines as $i => $line) {
            $isHeading = preg_match('/^#{1,6}\s/', $line) === 1;
            if ($isHeading && $current !== '') {
                $segments[] = $current;
                $current = '';
            }
            $current .= $i === array_key_last($lines) ? $line : $line . "\n";
        }

        if ($current !== '') {
            $segments[] = $current;
        }

        return $segments;
    }
}
