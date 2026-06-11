<?php

namespace anvildev\beacon\helpers;

class Json
{
    /**
     * Encodes a value as JSON, throwing on failure instead of returning `false`.
     *
     * @throws \JsonException
     */
    public static function encode(mixed $value, int $flags = 0): string
    {
        return json_encode($value, $flags | JSON_THROW_ON_ERROR);
    }

    /**
     * Decodes a JSON-encoded list of strings, dropping non-string entries
     * and re-keying as a list. Returns `[]` for null/empty/invalid input.
     *
     * @return list<string>
     */
    public static function decodeStringList(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : [];
    }

    /**
     * Normalises a raw column value (JSON string, PHP array, or anything else)
     * to an array, or `null` when neither form parses.
     *
     * @return array<mixed, mixed>|null
     */
    public static function decodeAssoc(mixed $raw): ?array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        }
        return is_array($raw) ? $raw : null;
    }

    /**
     * Decodes a JSON object string, returning `null` when the payload is empty,
     * invalid, or not an object/array.
     *
     * @return array<mixed, mixed>|null
     */
    public static function decodeObject(string $json, int $depth = 16): ?array
    {
        $trimmed = trim($json);
        if ($trimmed === '') {
            return null;
        }
        $decoded = json_decode($trimmed, true, max(1, $depth));
        return is_array($decoded) && json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
