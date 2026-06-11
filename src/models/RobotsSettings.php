<?php

namespace anvildev\beacon\models;

/**
 * @phpstan-type UserAgentRule array{userAgent: string, allow?: list<string>, disallow?: list<string>}
 */
class RobotsSettings
{
    /**
     * @param list<UserAgentRule> $userAgentRules
     */
    public function __construct(
        public readonly int $siteId,
        public readonly string $sitemapUrl = 'auto',
        public readonly array $userAgentRules = [],
    ) {
    }
}
