<?php

namespace anvildev\beacon\models;

use anvildev\beacon\enums\RedirectQueryStringMode;

class Redirect
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $siteId,
        public readonly string $sourceUri,
        public readonly string $targetUri,
        public readonly int $statusCode,
        /**
         * Matcher handle — one of the {@see \anvildev\beacon\enums\RedirectType}
         * backing values, or the handle of a third-party matcher registered
         * via {@see \anvildev\beacon\services\RedirectMatcher::EVENT_REGISTER_REDIRECT_TYPES}.
         */
        public readonly string $type,
        public readonly string $resolvedTarget,
        public readonly RedirectQueryStringMode $queryStringMode = RedirectQueryStringMode::Ignore,
    ) {
    }
}
