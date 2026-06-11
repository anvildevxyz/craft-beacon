<?php

namespace anvildev\beacon\helpers;

/**
 * Lightweight string helpers shared across controllers and services.
 */
final class Strings
{
    /** @return list<string> */
    public static function splitLines(string $input, bool $trim = true): array
    {
        $lines = explode("\n", strtr($input, ["\r\n" => "\n", "\r" => "\n"]));
        if ($trim) {
            $lines = array_map(trim(...), $lines);
        }
        return array_values(array_filter($lines, static fn(string $l): bool => $l !== ''));
    }

    public static function trimToNull(mixed $value): ?string
    {
        return is_string($value) && ($v = trim($value)) !== '' ? $v : null;
    }

    /**
     * Whether a value contains a carriage return or line feed. Used to reject
     * redirect/short-link URIs, which must be single-line.
     */
    public static function containsLineBreaks(string $value): bool
    {
        return str_contains($value, "\r") || str_contains($value, "\n");
    }

    /**
     * Removes all CR and LF characters from a value, collapsing it to a single
     * logical line. Used when embedding user-supplied strings in protocols that
     * prohibit line breaks (robots.txt directives, llms.txt headings, headers).
     */
    public static function stripLineBreaks(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }

    /**
     * Parses `key: value` lines into a flat scalar map. Empty lines and lines
     * without a `:` are dropped silently. Keys and values are trimmed, and
     * surrounding single/double quotes around the value are stripped if balanced.
     * Later occurrences of the same key overwrite earlier ones.
     *
     * @return array<string,string>
     */
    public static function parseKeyValueLines(string $raw): array
    {
        $out = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || ($colon = strpos($line, ':')) === false) {
                continue;
            }
            $key = trim(substr($line, 0, $colon));
            if ($key === '') {
                continue;
            }
            $value = trim(substr($line, $colon + 1));
            // strlen >= 2 guard: a lone `"` or `'` must NOT be unwrapped to ''.
            if (
                strlen($value) >= 2
                && ($q = $value[0]) === $value[-1]
                && ($q === '"' || $q === "'")
            ) {
                $value = substr($value, 1, -1);
            }
            $out[$key] = $value;
        }
        return $out;
    }
}
