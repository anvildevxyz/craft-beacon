<?php

namespace anvildev\beacon\models;

class BreadcrumbSettings
{
    public function __construct(
        public readonly int $siteId,
        public readonly bool $enabled = true,
        public readonly string $homeLabel = 'Home',
    ) {
    }
}
