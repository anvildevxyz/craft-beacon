<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\services\AiClient;
use PHPUnit\Framework\TestCase;

/**
 * Network-free coverage of {@see AiClient}'s embeddings surface.
 *
 * Outside a booted Craft app the client falls back to inert {@see \anvildev\beacon\models\Settings}
 * defaults (no key, no model), so {@see AiClient::embeddingsConfigured()} reports
 * false and the empty-input batch short-circuits before any provider is built.
 */
class AiClientEmbeddingsTest extends TestCase
{
    private AiClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new AiClient();
    }

    public function testEmbeddingsNotConfiguredWithEmptyModel(): void
    {
        $this->assertFalse($this->client->embeddingsConfigured(''));
    }

    public function testEmbeddingsNotConfiguredWithModelButNoKey(): void
    {
        // A model is present but no API key (inert Settings default) — still unconfigured.
        $this->assertFalse($this->client->embeddingsConfigured('text-embedding-3-small'));
    }

    public function testEmbeddingsNotConfiguredWithModelAndEmptyKey(): void
    {
        $this->assertFalse($this->client->embeddingsConfigured('text-embedding-3-small', ''));
    }

    public function testEmbeddingsConfiguredWithModelAndKey(): void
    {
        $this->assertTrue($this->client->embeddingsConfigured('text-embedding-3-small', 'sk-test-key'));
    }

    public function testEmbeddingsNotConfiguredWithKeyButEmptyModel(): void
    {
        // A key without a model is still unconfigured.
        $this->assertFalse($this->client->embeddingsConfigured('', 'sk-test-key'));
    }

    public function testEmbedBatchShortCircuitsOnEmptyInput(): void
    {
        // Empty input returns [] before any provider is resolved — no network, no throw.
        $this->assertSame([], $this->client->embedBatch([], 'text-embedding-3-small'));
    }

    public function testEmbedBatchShortCircuitsWithoutModelOrKey(): void
    {
        // The empty-input guard runs before the configuration check, so even an
        // unconfigured client returns [] for an empty batch.
        $this->assertSame([], $this->client->embedBatch([], ''));
    }
}
