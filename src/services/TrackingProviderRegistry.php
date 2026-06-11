<?php

namespace anvildev\beacon\services;

use anvildev\beacon\events\RegisterTrackingProvidersEvent;
use anvildev\beacon\tracking\TrackingScriptProviderInterface;
use yii\base\Component;

/**
 * Registry of tracking-script provider templates.
 *
 * Built-in providers (GA4, GTM, Facebook Pixel, Matomo, Custom) are registered
 * by Plugin::init(). Third-party plugins extend the catalog by listening to
 * EVENT_REGISTER_PROVIDERS.
 *
 * Handle uniqueness: registering two providers with the same handle throws
 * \InvalidArgumentException. This is intentional — the registry is a flat
 * lookup table and silent override would surprise users who expect a known
 * handle to map to a known implementation. To replace a built-in (e.g. swap
 * GA4 for a consent-aware variant), register your provider under a distinct
 * handle (e.g. 'ga4_consent') and select between them via configuration.
 */
class TrackingProviderRegistry extends Component
{
    public const EVENT_REGISTER_PROVIDERS = 'registerTrackingProviders';

    /** @var array<string, TrackingScriptProviderInterface> */
    private array $providers = [];

    public function init(): void
    {
        parent::init();

        $event = new RegisterTrackingProvidersEvent();
        $this->trigger(self::EVENT_REGISTER_PROVIDERS, $event);

        foreach ($event->providers as $provider) {
            $handle = $provider->getHandle();
            if (isset($this->providers[$handle])) {
                throw new \InvalidArgumentException("Duplicate tracking provider handle: {$handle}");
            }
            $this->providers[$handle] = $provider;
        }
    }

    public function get(string $handle): ?TrackingScriptProviderInterface
    {
        return $this->providers[$handle] ?? null;
    }

    /** @return array<string, TrackingScriptProviderInterface> */
    public function all(): array
    {
        return $this->providers;
    }
}
