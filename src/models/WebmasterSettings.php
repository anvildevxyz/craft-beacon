<?php

namespace anvildev\beacon\models;

/**
 * Holds the per-site IndexNow key.
 */
class WebmasterSettings
{
    public function __construct(
        public readonly int $siteId,
        public ?string $indexNowKey = null,
    ) {
    }
}
