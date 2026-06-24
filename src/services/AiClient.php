<?php

namespace anvildev\beacon\services;

use anvildev\beacon\models\Settings;
use anvildev\beacon\Plugin;
use anvildev\beacon\services\ai\AiException;
use anvildev\beacon\services\ai\AiProviderInterface;
use anvildev\beacon\services\ai\AnthropicProvider;
use anvildev\beacon\services\ai\OpenAiEmbeddingProvider;
use anvildev\beacon\services\ai\OpenAiProvider;
use yii\base\Component;

/**
 * Provider-agnostic gateway to an LLM chat/completion endpoint, plus an
 * OpenAI-compatible embeddings transport used by the Links feature for
 * semantic suggestion scoring.
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
     * Test seam: inject a fake embeddings provider so unit tests never hit the
     * network. When null, the provider is built from the passed config (falling
     * back to global AI settings) on demand.
     */
    public ?OpenAiEmbeddingProvider $embeddingProvider = null;

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

    /**
     * True when an embeddings endpoint can be built. Embeddings carry their own
     * config (the Links feature stores model/key/base-url separately) and fall
     * back to the global AI key/base-url when those are blank. Callers MUST
     * check this before {@see self::embed()} so an unconfigured install stays
     * inert.
     */
    public function embeddingsConfigured(string $model, ?string $apiKey = null, ?string $baseUrl = null): bool
    {
        if ($this->embeddingProvider !== null) {
            return true;
        }
        $settings = $this->settings();
        $key = ($apiKey !== null && $apiKey !== '') ? $apiKey : (string) $settings->aiApiKey;
        return trim($key) !== '' && trim($model) !== '';
    }

    /**
     * Embed a single string. Returns the vector, or throws when unconfigured.
     *
     * @return list<float>
     * @throws AiException
     */
    public function embed(string $text, string $model, ?string $apiKey = null, ?string $baseUrl = null): array
    {
        $vectors = $this->embedBatch([$text], $model, $apiKey, $baseUrl);
        return $vectors[0] ?? [];
    }

    /**
     * Embed a batch of strings in a single request. Vectors are returned in
     * input order. Throws when unconfigured (defence in depth) or on transport
     * error.
     *
     * @param list<string> $texts
     * @return list<list<float>>
     * @throws AiException
     */
    public function embedBatch(array $texts, string $model, ?string $apiKey = null, ?string $baseUrl = null): array
    {
        if ($texts === []) {
            return [];
        }
        $provider = $this->resolveEmbeddingProvider($model, $apiKey, $baseUrl);
        if ($provider === null) {
            throw new AiException('Embeddings are not configured.');
        }
        return $provider->embed($texts);
    }

    private function resolveEmbeddingProvider(string $model, ?string $apiKey, ?string $baseUrl): ?OpenAiEmbeddingProvider
    {
        if ($this->embeddingProvider !== null) {
            return $this->embeddingProvider;
        }
        if (!$this->embeddingsConfigured($model, $apiKey, $baseUrl)) {
            return null;
        }
        $settings = $this->settings();
        $key = ($apiKey !== null && $apiKey !== '') ? $apiKey : (string) $settings->aiApiKey;
        $base = ($baseUrl !== null && $baseUrl !== '') ? $baseUrl : $settings->aiBaseUrl;
        return new OpenAiEmbeddingProvider($key, $model, $base);
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
