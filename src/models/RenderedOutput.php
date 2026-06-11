<?php

namespace anvildev\beacon\models;

use DateTimeInterface;

class RenderedOutput
{
    public function __construct(
        public readonly string $content,
        public readonly DateTimeInterface $generatedAt,
        public readonly ?DateTimeInterface $validUntil = null,
    ) {
    }

    /**
     * Whether the row's TTL has lapsed. A null `validUntil` means no TTL —
     * the row stays fresh until event-driven invalidation drops it.
     */
    public function isExpired(DateTimeInterface $now): bool
    {
        return $this->validUntil !== null && $this->validUntil < $now;
    }
}
