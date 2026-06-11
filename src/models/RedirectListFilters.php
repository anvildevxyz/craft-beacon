<?php

namespace anvildev\beacon\models;

final readonly class RedirectListFilters
{
    public function __construct(
        public string $q = '',
        public string $statusCode = '',
        public string $type = '',
        public string $enabled = '',
        public string $source = '',
        public string $stale = '',
        public string $sort = 'hits_desc',
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function fromQueryParams(array $params): self
    {
        return new self(
            q: trim((string) ($params['q'] ?? '')),
            statusCode: (string) ($params['statusCode'] ?? ''),
            type: (string) ($params['type'] ?? ''),
            enabled: (string) ($params['enabled'] ?? ''),
            source: (string) ($params['source'] ?? ''),
            stale: (string) ($params['stale'] ?? ''),
            sort: (string) ($params['sort'] ?? 'hits_desc'),
        );
    }
}
