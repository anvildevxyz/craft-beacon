<?php

namespace anvildev\beacon\helpers;

use anvildev\beacon\Plugin;
use Craft;

/**
 * Resolves the site's `/llms.txt` URL for `rel="describedby"` discovery —
 * shared by the HTML `<head>` (see {@see \anvildev\beacon\variables\BeaconVariable})
 * and the Markdown response surfaces (see {@see MarkdownResponse}), so both
 * agree on when llms.txt is actually reachable for a site.
 */
final class LlmsDiscovery
{
    /**
     * Returns the site's absolute `/llms.txt` URL, or null when llms.txt is
     * disabled for that site, the site can't be resolved, or it has no base URL.
     */
    public static function describedByUrl(int $siteId): ?string
    {
        if (Craft::$app === null || Plugin::$plugin === null) {
            return null;
        }
        $site = Craft::$app->getSites()->getSiteById($siteId);
        if ($site === null || !Plugin::$plugin->siteSettings->getLlms($siteId)->enabled) {
            return null;
        }
        $siteUrl = $site->getBaseUrl();
        if (!is_string($siteUrl) || trim($siteUrl) === '') {
            return null;
        }
        return rtrim($siteUrl, '/') . '/llms.txt';
    }
}
