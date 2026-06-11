<?php

namespace anvildev\beacon\helpers;

/**
 * XML escaping helpers for sitemap/feed/extra-sitemap output.
 * The flag combination is non-obvious and load-bearing: ENT_XML1 enforces
 * XML-mode entities (avoids HTML5 entity leakage), ENT_QUOTES escapes both
 * `'` and `"` so attributes are safe.
 */
final class Xml
{
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * XML declaration plus the opening <urlset> tag carrying the base sitemaps
     * namespace and any extension namespaces (prefix => uri), e.g.
     * ['news' => 'http://www.google.com/schemas/sitemap-news/0.9'].
     *
     * @param array<string, string> $extraNamespaces Map of prefix => namespace URI
     */
    public static function urlsetOpen(array $extraNamespaces = []): string
    {
        $attrs = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        foreach ($extraNamespaces as $prefix => $uri) {
            // Prefixes are NCNames and URIs are attribute values; escape both so a
            // third-party RegisterSitemapExtrasEvent supplying dynamic values can't
            // break out of the tag. Prefix is additionally constrained to XML name chars.
            $safePrefix = preg_replace('/[^A-Za-z0-9._-]/', '', $prefix);
            if ($safePrefix === '') {
                continue;
            }
            $attrs .= ' xmlns:' . $safePrefix . '="' . self::escape($uri) . '"';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n<urlset {$attrs}>";
    }
}
