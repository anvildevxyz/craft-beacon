<?php

namespace anvildev\beacon\models;

class AdsSettings
{
    public function __construct(
        public readonly int $siteId,
        public readonly bool $enabled = false,
        public readonly ?int $assetId = null,
        public readonly ?string $body = null,
    ) {
    }
}
