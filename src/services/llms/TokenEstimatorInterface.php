<?php

namespace anvildev\beacon\services\llms;

/**
 * Estimates the LLM token count of a string.
 *
 * Beacon ships a dependency-free heuristic ({@see HeuristicTokenEstimator}).
 * The interface exists so a site that needs exact, model-specific counts can
 * bind its own implementation (e.g. a real BPE tokenizer) to the
 * `Plugin::$plugin->tokenEstimator` component without touching callers.
 */
interface TokenEstimatorInterface
{
    /**
     * Returns the estimated number of tokens in `$text`. Empty/whitespace-only
     * input returns 0; any non-empty input returns at least 1.
     */
    public function estimate(string $text): int;
}
