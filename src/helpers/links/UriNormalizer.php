<?php

namespace anvildev\beacon\helpers\links;

final class UriNormalizer
{
    public static function normalize(string $uri): string
    {
        // Strip query string
        $pos = strpos($uri, '?');
        if ($pos !== false) {
            $uri = substr($uri, 0, $pos);
        }
        // Collapse multiple slashes
        $uri = preg_replace('#/+#', '/', $uri) ?? $uri;
        // Trim slashes
        $uri = trim($uri, '/');
        // Lowercase (multibyte-safe)
        return mb_strtolower($uri, 'UTF-8');
    }
}
