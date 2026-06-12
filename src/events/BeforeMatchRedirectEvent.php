<?php

namespace anvildev\beacon\events;

use anvildev\beacon\models\Redirect;
use yii\base\Event;

/**
 * Fired by {@see \anvildev\beacon\services\RedirectService::findRedirect()}
 * before its built-in matching runs. Subscribers can short-circuit the lookup
 * by assigning to `$redirect`, or block a particular URI from ever
 * redirecting by setting `$isHandled = true` with `$redirect = null`.
 *
 * Cancellation contract:
 * - `$redirect = SomeRedirect` → service returns it immediately, hits are
 *   still recorded.
 * - `$isHandled = true; $redirect = null` → service skips matching and
 *   returns `null` (no redirect issued; useful e.g. for logged-in users).
 * - both unset → service runs its normal exact/wildcard lookup.
 *
 * @since 1.0.0
 */
final class BeforeMatchRedirectEvent extends Event
{
    public ?Redirect $redirect = null;
    public bool $isHandled = false;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public readonly int $siteId,
        public readonly string $uri,
        array $config = [],
    ) {
        parent::__construct($config);
    }
}
