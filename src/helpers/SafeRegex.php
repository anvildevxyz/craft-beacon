<?php

namespace anvildev\beacon\helpers;

use Craft;

/**
 * Bounded regex execution for admin-supplied patterns.
 *
 * The plugin runs editor-saved regex against the request URI (redirects) and
 * incoming User-Agent (bot detection) on every request. A pathological pattern
 * (`^(a+)+$`) would otherwise stall a worker on a single shaped request, so
 * every match runs under a lowered `pcre.backtrack_limit` and patterns are
 * length-capped at save time.
 */
final class SafeRegex
{
    public const MAX_PATTERN_LENGTH = 256;
    private const RUNTIME_BACKTRACK_LIMIT = '100000';

    /**
     * Run `preg_match` with a lowered backtrack limit. Returns null on
     * compile failure, backtrack overflow, or no match.
     *
     * @param array<int|string,string> $matches Populated on success.
     */
    public static function match(string $delimitedPattern, string $subject, ?array &$matches = null): ?bool
    {
        $matches = [];
        $previous = ini_set('pcre.backtrack_limit', self::RUNTIME_BACKTRACK_LIMIT);
        try {
            $result = @preg_match($delimitedPattern, $subject, $matches);
        } finally {
            if ($previous !== false) {
                ini_set('pcre.backtrack_limit', $previous);
            }
        }
        if ($result === false) {
            if (Craft::$app !== null) {
                Craft::warning('Beacon regex match failed: ' . preg_last_error_msg() . " (pattern={$delimitedPattern})", 'beacon');
            }
            return null;
        }
        return $result === 1;
    }

    /**
     * Reject patterns that are too long or whose own compilation/probe match
     * fails under the same lowered backtrack limit used at runtime. Used at
     * save time so pathological patterns never reach the request hot path.
     *
     * @return string|null Validation error message, or null when the pattern is acceptable.
     */
    public static function validate(string $rawPattern, string $delimiter = '#'): ?string
    {
        if ($rawPattern === '') {
            return Craft::t('beacon', 'Pattern cannot be empty.');
        }
        if (strlen($rawPattern) > self::MAX_PATTERN_LENGTH) {
            return Craft::t('beacon', 'Pattern exceeds {max} characters.', ['max' => self::MAX_PATTERN_LENGTH]);
        }
        $delimited = $delimiter . str_replace($delimiter, '\\' . $delimiter, $rawPattern) . $delimiter;
        $previous = ini_set('pcre.backtrack_limit', self::RUNTIME_BACKTRACK_LIMIT);
        try {
            $result = @preg_match($delimited, str_repeat('a', 64) . '!');
        } finally {
            if ($previous !== false) {
                ini_set('pcre.backtrack_limit', $previous);
            }
        }
        return $result === false ? Craft::t('beacon', 'Pattern is invalid or too expensive to evaluate.') : null;
    }
}
