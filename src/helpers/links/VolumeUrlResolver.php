<?php

namespace anvildev\beacon\helpers\links;

final class VolumeUrlResolver
{
    /**
     * @param list<array{id: int, baseUrl: string}> $volumes
     * @return array<string, int> prefix → volumeId, sorted by prefix length descending
     */
    public static function buildPrefixMap(array $volumes): array
    {
        $map = [];
        foreach ($volumes as $volume) {
            $base = rtrim((string) $volume['baseUrl'], '/');
            if ($base === '') {
                continue;
            }
            $map[$base] = (int) $volume['id'];
        }
        uksort($map, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        return $map;
    }

    /**
     * @param array<string, int> $prefixMap
     * @return array{volumeId: int, path: string}|null
     */
    public static function matchPrefix(string $url, array $prefixMap): ?array
    {
        // Strip query + fragment
        $clean = $url;
        $qPos = strpos($clean, '?');
        if ($qPos !== false) {
            $clean = substr($clean, 0, $qPos);
        }
        $fPos = strpos($clean, '#');
        if ($fPos !== false) {
            $clean = substr($clean, 0, $fPos);
        }
        foreach ($prefixMap as $prefix => $volumeId) {
            if (str_starts_with($clean, $prefix . '/')) {
                $path = substr($clean, strlen($prefix) + 1);
                return ['volumeId' => $volumeId, 'path' => $path];
            }
        }
        return null;
    }

    /**
     * Removes a single Craft transform directory segment from an asset path.
     * Transforms appear as _{WIDTH}x{HEIGHT}_{mode}_{position}/ or _{handle}/.
     * The segment sits immediately before the filename.
     */
    public static function stripTransformSegment(string $path): string
    {
        // Match a segment that starts with underscore, contains no slashes,
        // and sits between other path segments (or as the parent of the filename).
        return preg_replace('#/_[^/]+/(?=[^/]+$)#', '/', $path) ?? $path;
    }
}
