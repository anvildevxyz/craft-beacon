<?php

namespace anvildev\beacon\models;

/**
 * Read model returned from {@see \anvildev\beacon\services\ShortLinkService::findBySlug()}
 * to the 404 listener — kept narrow so the hot path doesn't drag a full
 * ActiveRecord through a per-request resolution.
 */
class ShortLink
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $siteId,
        public readonly string $slug,
        public readonly string $destination,
        public readonly int $statusCode,
    ) {
    }
}
