<?php

namespace anvildev\beacon\models;

class AiCrawlerRule
{
    /**
     * @param list<string> $allowPaths
     * @param list<string> $disallowPaths
     */
    public function __construct(
        public readonly int $id,
        public readonly string $botName,
        public readonly array $allowPaths,
        public readonly array $disallowPaths,
        public readonly int $sortOrder,
        public readonly bool $enabled,
    ) {
    }
}
