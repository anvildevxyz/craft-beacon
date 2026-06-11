<?php

namespace anvildev\beacon\events;

use anvildev\beacon\models\Redirect;
use yii\base\Event;

/**
 * Fired by {@see \anvildev\beacon\services\RedirectService::findRedirect()}
 * after a redirect has matched, before it is returned to the 404 handler.
 * Subscribers can rewrite `$resolvedTarget` / `$statusCode` (e.g. to append
 * an affiliate tag, or to demote a 301 to a 302 for staging) by reassigning
 * `$redirect` to a new {@see Redirect} instance with the updated values.
 *
 * Setting `$redirect = null` cancels the redirect entirely (the 404 stays
 * a 404).
 *
 * @since 2.2.0
 */
final class AfterMatchRedirectEvent extends Event
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public ?Redirect $redirect,
        public readonly string $uri,
        array $config = [],
    ) {
        parent::__construct($config);
    }
}
