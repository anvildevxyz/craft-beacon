<?php

namespace anvildev\beacon\models;

class LlmsSettings
{
    /**
     * @param list<string> $sections
     */
    public function __construct(
        public readonly int $siteId,
        public readonly bool $enabled = true,
        public readonly ?string $summary = null,
        public readonly ?string $siteNameOverride = null,
        public readonly array $sections = [],
        public readonly ?string $policyUrl = null,
        public readonly ?string $licenseUrl = null,
        public readonly ?string $contactEmail = null,
        public readonly ?string $preferredAttribution = null,
        public readonly ?string $fullBody = null,
    ) {
    }
}
