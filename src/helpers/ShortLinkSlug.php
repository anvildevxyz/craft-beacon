<?php

namespace anvildev\beacon\helpers;

use Craft;

/**
 * Slug validation shared by the short-link element and importer so elements
 * do not depend on {@see \anvildev\beacon\services\ShortLinkService}.
 */
final class ShortLinkSlug
{
    /**
     * Validates a short-link slug. Returns null when safe, otherwise an
     * error string for the caller to surface. Mirrors the RedirectImporter
     * URL-allowlist contract: only ASCII-friendly slug characters allowed, no
     * leading slash (we add the `/` at lookup time), no reserved Beacon / Craft
     * prefixes that would collide with element routing.
     */
    public static function validate(string $slug): ?string
    {
        if ($slug === '') {
            return Craft::t('beacon', 'validation.shortLink.slug.required');
        }
        if (str_starts_with($slug, '/')) {
            return Craft::t('beacon', 'validation.shortLink.slug.must.not.start.forward');
        }
        if (mb_strlen($slug) > 128) {
            return Craft::t('beacon', 'validation.shortLink.slug.exceeds.128.characters');
        }
        if (preg_match('#^[A-Za-z0-9._\-/]+$#', $slug) !== 1) {
            return Craft::t('beacon', 'validation.shortLink.slug.may.only.contain.letters');
        }
        $firstSeg = strstr($slug, '/', true) ?: $slug;
        if (in_array($firstSeg, ['admin', 'api', 'cpresources', 'actions', 'index.php', '.well-known'], true)) {
            return Craft::t('beacon', 'validation.shortLink.slug.reserved.by.craft.pick', [
                'segment' => $firstSeg,
            ]);
        }

        return null;
    }
}
