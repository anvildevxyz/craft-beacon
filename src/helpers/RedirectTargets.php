<?php

namespace anvildev\beacon\helpers;

use Craft;

/**
 * Shared validation for redirect and short-link target URIs.
 *
 * Stored destinations are emitted verbatim as `Location:` headers, so they
 * must be limited to relative paths or http(s) URLs. Without this allowlist,
 * values like `//evil.example` or `javascript:` become open-redirect vectors.
 */
final class RedirectTargets
{
    /**
     * Returns null when the target is safe, otherwise a translated error string.
     *
     * Accepts path-relative (`/foo`) and absolute `http(s)://` URLs.
     * Rejects protocol-relative (`//evil.example`), `javascript:`, `data:`,
     * `vbscript:`, `file:`, and any other scheme.
     */
    public static function validateTargetUri(string $target): ?string
    {
        if (str_starts_with($target, '//')) {
            return Craft::t('beacon', 'Target must not be protocol-relative.');
        }
        if (str_starts_with($target, '/')) {
            return null;
        }
        if (!preg_match('#^https?://#i', $target)) {
            return Craft::t('beacon', 'Target must be a relative path (starting with "/") or an http(s):// URL.');
        }
        if (parse_url($target, PHP_URL_HOST) === null) {
            return Craft::t('beacon', 'Target URL is malformed.');
        }
        return null;
    }
}
