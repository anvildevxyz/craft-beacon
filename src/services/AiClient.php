<?php

namespace anvildev\beacon\services;

use anvildev\beacon\models\Settings;
use anvildev\beacon\Plugin;
use anvildev\beacon\services\ai\AiException;
use anvildev\beacon\services\ai\AiProviderInterface;
use anvildev\beacon\services\ai\AnthropicProvider;
use anvildev\beacon\services\ai\OpenAiProvider;
use yii\base\Component;

/**
 * Provider-agnostic gateway to an LLM chat/completion endpoint.
 *
 * Dormant by default: {@see self::isConfigured()} stays false until an
 * operator enables AI and supplies a model + API key, and {@see self::complete()}
 * makes zero network calls until then. Supports Anthropic and any
 * OpenAI-compatible endpoint; the {@see AiProviderInterface} seam lets a
 * streaming transport be added later without touching callers.
 *
 * Wire-up: registered as `Plugin::$plugin->aiClient`. The API key and provider
 * config live on the global {@see Settings} (DB-backed, with `config/beacon.php`
 * overrides for `aiApiKey` / `aiBaseUrl` / `aiModel` / `aiProvider`).
 */
class AiClient extends Component
{
    /**
     * Test seam: inject a fake provider so unit tests never hit the network.
     * When null, the provider is built from plugin settings on demand.
     */
    public ?AiProviderInterface $provider = null;

    /**
     * True when AI generation is enabled and a model + key are present. Callers
     * MUST check this before {@see self::complete()} so an unconfigured install
     * stays inert (no affordances, no requests).
     */
    public function isConfigured(): bool
    {
        if ($this->provider !== null) {
            return true;
        }
        $settings = $this->settings();
        return $settings->aiEnabled
            && trim((string) $settings->aiApiKey) !== ''
            && trim($settings->aiModel) !== '';
    }

    /**
     * Run one completion. Throws when unconfigured (defence in depth — the UI
     * also hides the affordances) or when the provider errors.
     *
     * @param array<string,mixed> $options
     * @throws AiException
     */
    public function complete(string $system, string $user, array $options = []): string
    {
        $provider = $this->resolveProvider();
        if ($provider === null) {
            throw new AiException('AI generation is not configured.');
        }
        return $provider->complete($system, $user, $options);
    }

    private function resolveProvider(): ?AiProviderInterface
    {
        if ($this->provider !== null) {
            return $this->provider;
        }
        if (!$this->isConfigured()) {
            return null;
        }
        $settings = $this->settings();
        $key = (string) $settings->aiApiKey;
        return match (strtolower($settings->aiProvider)) {
            'openai', 'openai-compatible' => new OpenAiProvider($key, $settings->aiModel, $settings->aiBaseUrl),
            default => new AnthropicProvider($key, $settings->aiModel, $settings->aiBaseUrl),
        };
    }

    private function settings(): Settings
    {
        // Defensive: outside a booted Craft app (e.g. unit tests with an
        // injected provider) fall back to inert defaults rather than fataling.
        $plugin = Plugin::$plugin;
        return $plugin !== null ? $plugin->settings->get() : new Settings();
    }
}
