<?php

namespace anvildev\beacon\models;

class AiBot
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $userAgentPattern,
        public readonly bool $enabled,
        public readonly string $source,
        public readonly int $sortOrder,
    ) {
    }
}
