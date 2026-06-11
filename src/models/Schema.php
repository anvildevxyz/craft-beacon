<?php

namespace anvildev\beacon\models;

class Schema
{
    /**
     * @param array<string,string> $mapping
     */
    public function __construct(
        public readonly int $id,
        public readonly string $entryTypeHandle,
        public readonly string $schemaType,
        public readonly array $mapping,
        public readonly int $sortOrder,
        public readonly bool $enabled,
    ) {
    }
}
