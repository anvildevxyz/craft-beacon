<?php

namespace anvildev\beacon\services\ai;

use Craft;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Anthropic Messages API transport. Reads the API key, model, and an optional
 * base-URL override from the constructor so the {@see \anvildev\beacon\services\AiClient}
 * can build it from settings while tests inject a fake instead.
 */
final class AnthropicProvider implements AiProviderInterface
{
    private const DEFAULT_BASE = 'https://api.anthropic.com';
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly ?string $baseUrl = null,
        private readonly int $maxTokens = 1024,
    ) {
    }

    public function complete(string $system, string $user, array $options = []): string
    {
        $base = rtrim(($this->baseUrl !== null && $this->baseUrl !== '') ? $this->baseUrl : self::DEFAULT_BASE, '/');
        $maxTokens = isset($options['maxTokens']) && is_numeric($options['maxTokens'])
            ? (int) $options['maxTokens']
            : $this->maxTokens;

        try {
            $response = Craft::createGuzzleClient(['timeout' => 30])->post($base . '/v1/messages', [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                    'content-type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'max_tokens' => $maxTokens,
                    'system' => $system,
                    'messages' => [['role' => 'user', 'content' => $user]],
                ],
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new AiException('Anthropic request failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($status < 200 || $status >= 300) {
            throw new AiException("Anthropic returned HTTP {$status}: " . substr($body, 0, 300));
        }

        $data = json_decode($body, true);
        $text = (is_array($data) && isset($data['content'][0]['text']) && is_string($data['content'][0]['text']))
            ? $data['content'][0]['text']
            : null;
        if ($text === null || trim($text) === '') {
            throw new AiException('Anthropic response contained no text content.');
        }

        return trim($text);
    }
}
