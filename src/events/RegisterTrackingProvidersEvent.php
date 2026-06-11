<?php

namespace anvildev\beacon\events;

use anvildev\beacon\tracking\TrackingScriptProviderInterface;
use yii\base\Event;

final class RegisterTrackingProvidersEvent extends Event
{
    /** @var list<TrackingScriptProviderInterface> */
    public array $providers = [];
}
