<?php

namespace anvildev\beacon\helpers\links;

class LinkParser
{
    /** @return string[] */
    public static function extractUrls(string $html, string $siteUrl): array
    {
        if (trim($html) === '') {
            return [];
        }
        $siteUrl = rtrim($siteUrl, '/');
        preg_match_all('/<a\s[^>]*href=["\']([^"\']*)["\'][^>]*>/i', $html, $matches);
        if (empty($matches[1])) {
            return [];
        }
        $urls = [];
        foreach ($matches[1] as $href) {
            $normalized = self::normalizeUrl($href, $siteUrl);
            if ($normalized !== null) {
                $urls[$normalized] = true;
            }
        }
        return array_keys($urls);
    }

    /**
     * @return array<int, array{url: string, anchorText: string, isExternal: bool}>
     */
    public static function extractLinks(string $html, string $siteUrl): array
    {
        if (trim($html) === '') {
            return [];
        }
        $siteUrl = rtrim($siteUrl, '/');
        preg_match_all('/<a\s[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is', $html, $matches);
        if (empty($matches[1])) {
            return [];
        }
        $links = [];
        foreach ($matches[1] as $i => $href) {
            $href = trim($href);
            if ($href === '' || $href[0] === '#') {
                continue;
            }
            // Allowlist: only http, https, relative paths (/…), and protocol-relative (//…)
            if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $href, $schemeMatch)) {
                $scheme = strtolower($schemeMatch[1]);
                if ($scheme !== 'http' && $scheme !== 'https') {
                    continue;
                }
            }
            $anchorText = trim(strip_tags($matches[2][$i]));
            // Protocol-relative URLs (//host/path) resolve against the current
            // scheme; normalize to https so they classify as external (or
            // same-site internal) instead of being treated as a root path.
            if (str_starts_with($href, '//')) {
                $href = 'https:' . $href;
            }
            $isExternal = $href[0] !== '/' && !str_starts_with($href, $siteUrl);
            if ($isExternal) {
                $url = strtok($href, '#') ?: $href;
                $url = rtrim($url, '/');
            } else {
                $url = self::normalizeUrl($href, $siteUrl);
                if ($url === null) {
                    continue;
                }
            }
            if (!isset($links[$url])) {
                $links[$url] = ['url' => $url, 'anchorText' => $anchorText, 'isExternal' => $isExternal];
            }
        }
        return array_values($links);
    }

    private static function normalizeUrl(string $href, string $siteUrl): ?string
    {
        $href = trim($href);
        if ($href === '' || $href[0] === '#') {
            return null;
        }
        // Allowlist: only http, https, or relative paths (no scheme)
        if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $href, $schemeMatch)) {
            $scheme = strtolower($schemeMatch[1]);
            if ($scheme !== 'http' && $scheme !== 'https') {
                return null;
            }
        }
        // Protocol-relative URLs (//host/path) resolve against the current
        // scheme; normalize to https before the root-relative check so they
        // aren't mistakenly prefixed with the site URL.
        if (str_starts_with($href, '//')) {
            $href = 'https:' . $href;
        }
        if ($href[0] === '/') {
            $href = $siteUrl . $href;
        }
        if (!str_starts_with($href, $siteUrl)) {
            return null;
        }
        $href = strtok($href, '?#') ?: $href;
        $href = rtrim($href, '/');
        if ($href === $siteUrl) {
            return null;
        }
        return $href;
    }
}
