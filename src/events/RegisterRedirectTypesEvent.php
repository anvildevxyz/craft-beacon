<?php

namespace anvildev\beacon\events;

use anvildev\beacon\services\CustomRedirectMatcherInterface;
use yii\base\Event;

/**
 * Fired once per request the first time a wildcard lookup happens. Lets
 * third-party plugins register additional matching algorithms via
 * {@see CustomRedirectMatcherInterface}. The handle stored in a rule's
 * `type` column dispatches to the matching custom matcher.
 *
 * @since 1.0.0
 */
final class RegisterRedirectTypesEvent extends Event
{
    /** @var list<CustomRedirectMatcherInterface> */
    public array $types = [];
}
