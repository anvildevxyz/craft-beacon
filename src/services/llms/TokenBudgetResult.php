<?php

namespace anvildev\beacon\services\llms;

/**
 * Outcome of trimming a Markdown document to a token budget.
 */
final class TokenBudgetResult
{
    public function __construct(
        /** The (possibly trimmed) Markdown. */
        public readonly string $markdown,
        /** Estimated tokens of {@see self::$markdown} (the served body). */
        public readonly int $estimatedTokens,
        /** Estimated tokens of the original, untrimmed input. */
        public readonly int $originalTokens,
        /** True when one or more trailing sections were dropped to fit the budget. */
        public readonly bool $truncated,
    ) {
    }
}
