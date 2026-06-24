<?php

namespace anvildev\beacon\services\llms;

use yii\base\Component;

/**
 * Dependency-free token estimator. Tokenisation is model-specific (each model
 * ships its own BPE vocabulary), so an exact count is impossible without
 * pulling in the model's tokenizer. This estimator blends the two cheap
 * approximations the industry uses as rules of thumb:
 *
 *   - ~4 characters per token (good for prose, the OpenAI rule of thumb)
 *   - ~1.3 tokens per whitespace word (good for short/code-heavy text)
 *
 * and averages them. Expect roughly ±25% versus a real tokenizer — accurate
 * enough to size `llms-full.txt` against a context window without overshooting
 * by an order of magnitude. Documented as an approximation; swap in a real
 * tokenizer via {@see TokenEstimatorInterface} when exactness matters.
 */
final class HeuristicTokenEstimator extends Component implements TokenEstimatorInterface
{
    private const CHARS_PER_TOKEN = 4.0;
    private const TOKENS_PER_WORD = 1.3;

    public function estimate(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }

        $chars = mb_strlen($text);
        $words = count(preg_split('/\s+/u', $text) ?: []);

        $byChars = $chars / self::CHARS_PER_TOKEN;
        $byWords = $words * self::TOKENS_PER_WORD;

        return max(1, (int) ceil(($byChars + $byWords) / 2.0));
    }
}
