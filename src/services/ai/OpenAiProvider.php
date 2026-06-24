<?php

namespace anvildev\beacon\services\ai;

use Craft;
use GuzzleHttp\Exception\GuzzleException;

/**
 * OpenAI-compatible Chat Completions transport. The base-URL override lets it
 * target any compatible endpoint (Azure OpenAI, OpenRouter, a local gateway,
 * etc.) — only the `/v1/chat/completions` shape is assumed.
 */
final class OpenAiProvider implements AiProviderInterface
{
    private const DEFAULT_BASE = 'https://api.openai.com';

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
            $response = Craft::createGuzzleClient(['timeout' => 30])->post($base . '/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'content-type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'max_tokens' => $maxTokens,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                ],
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new AiException('OpenAI request failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($status < 200 || $status >= 300) {
            throw new AiException("OpenAI returned HTTP {$status}: " . substr($body, 0, 300));
        }

        $data = json_decode($body, true);
        $text = (is_array($data) && isset($data['choices'][0]['message']['content']) && is_string($data['choices'][0]['message']['content']))
            ? $data['choices'][0]['message']['content']
            : null;
        if ($text === null || trim($text) === '') {
            throw new AiException('OpenAI response contained no message content.');
        }

        return trim($text);
    }
}
