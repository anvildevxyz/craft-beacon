<?php

namespace anvildev\beacon\models;

class HumansSettings
{
    public function __construct(
        public readonly int $siteId,
        public readonly bool $enabled = false,
        public readonly ?string $body = null,
    ) {
    }
}
