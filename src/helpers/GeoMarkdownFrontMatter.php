<?php

namespace anvildev\beacon\helpers;

/**
 * Front-matter merge/encode/render helpers for GEO Markdown exports.
 * Layer precedence: left-to-right in {@see self::mergeLayers()} — later
 * layers override earlier keys (Site → Section → Element → entry override).
 */
final class GeoMarkdownFrontMatter
{
    /**
     * @param array<string,scalar|null> ...$layers
     * @return array<string,scalar|null>
     */
    public static function mergeLayers(array ...$layers): array
    {
        // Variadic spread merges left-to-right; array_merge() of zero args returns [].
        return array_merge(...$layers);
    }

    /**
     * JSON-encode a scalar front-matter value for YAML embedding.
     * Returns null when encoding fails or the value is empty.
     */
    public static function encodeValue(string|int|float|bool|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return Json::encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string,scalar|null> $merged
     */
    public static function render(array $merged): string
    {
        if ($merged === []) {
            return '';
        }

        $lines = array_filter(
            array_map(
                static fn(string $key, mixed $value): ?string => ($enc = self::encodeValue($value)) !== null
                    ? $key . ': ' . $enc
                    : null,
                array_keys($merged),
                $merged,
            ),
        );

        if ($lines === []) {
            return '';
        }

        return "---\n" . implode("\n", $lines) . "\n---\n\n";
    }
}
