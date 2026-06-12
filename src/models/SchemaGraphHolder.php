<?php

namespace anvildev\beacon\models;

/**
 * Mutable JSON-LD graph passed into {@see \anvildev\beacon\events\DefineSchemasEvent}.
 *
 * @since 1.0.0
 */
final class SchemaGraphHolder
{
    /**
     * @param list<array<string,mixed>> $nodes
     */
    public function __construct(
        public array $nodes = [],
    ) {
    }
}
