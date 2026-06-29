<?php

namespace anvildev\beacon\services\ai;

use Craft;
use GuzzleHttp\Exception\GuzzleException;

/**
 * OpenAI-compatible embeddings transport (`/v1/embeddings`).
 *
 * Kept separate from {@see OpenAiProvider} (chat) because embeddings frequently
 * run against a different model — and often a different endpoint/key — than the
 * chat completion provider: an install may use Anthropic for generation but an
 * OpenAI-compatible endpoint for vectors. Only the `{input, model}` request and
 * `data[].embedding` response shape is assumed, so Azure OpenAI, a local
 * gateway, or any compatible host works via the base-URL override.
 */
final class OpenAiEmbeddingProvider
{
    private const DEFAULT_BASE = 'https://api.openai.com';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly ?string $baseUrl = null,
    ) {
    }

    /**
     * Embed a batch of strings in one request. Returns vectors in the same
     * order as the input (the provider echoes an `index` per row, which we
     * sort by defensively rather than trusting array order).
     *
     * @param list<string> $texts
     * @return list<list<float>>
     * @throws AiException on transport failure, non-2xx status, or a malformed body
     */
    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $base = rtrim(($this->baseUrl !== null && $this->baseUrl !== '') ? $this->baseUrl : self::DEFAULT_BASE, '/');

        try {
            $response = Craft::createGuzzleClient(['timeout' => 30])->post($base . '/v1/embeddings', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'content-type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'input' => array_values($texts),
                ],
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new AiException('Embeddings request failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($status < 200 || $status >= 300) {
            throw new AiException("Embeddings endpoint returned HTTP {$status}: " . substr($body, 0, 300));
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
            throw new AiException('Embeddings response contained no data array.');
        }

        $rows = $data['data'];
        usort($rows, static fn(array $a, array $b): int => ((int) ($a['index'] ?? 0)) <=> ((int) ($b['index'] ?? 0)));

        $vectors = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['embedding']) || !is_array($row['embedding'])) {
                throw new AiException('Embeddings response row was missing its vector.');
            }
            $vectors[] = array_map(static fn($v): float => (float) $v, array_values($row['embedding']));
        }

        if (count($vectors) !== count($texts)) {
            throw new AiException(sprintf('Embeddings count mismatch: sent %d, received %d.', count($texts), count($vectors)));
        }

        return $vectors;
    }
}
