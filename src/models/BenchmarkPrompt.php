<?php

namespace anvildev\beacon\models;

/**
 * A single benchmark question asked of answer engines to measure whether the
 * site is cited. Per-site; managed from the AI-visibility CP screen.
 */
class BenchmarkPrompt
{
    public function __construct(
        public ?int $id = null,
        public int $siteId = 0,
        public string $prompt = '',
        public bool $enabled = true,
    ) {
    }
}
