<?php

namespace anvildev\beacon\models;

/**
 * @phpstan-type ImportError array{lineNumber:int, reason:string}
 */
class ImportResult
{
    /**
     * @param list<ImportError> $errors
     */
    public function __construct(
        public readonly int $insertedCount,
        public readonly int $skippedCount,
        public readonly array $errors = [],
    ) {
    }
}
