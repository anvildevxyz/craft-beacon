<?php

namespace anvildev\beacon\models;

/**
 * Per-site settings for the browser-side AI-agent tools script
 * (`/webmcp.js`).
 *
 * @author anvildev
 */
class WebMcpSettings
{
    /**
     * @param int $siteId The site these settings belong to.
     * @param bool $enabled Whether `/webmcp.js` is served for this site.
     */
    public function __construct(
        public readonly int $siteId,
        public readonly bool $enabled = false,
    ) {
    }
}
