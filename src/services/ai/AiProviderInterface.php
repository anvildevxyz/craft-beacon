<?php

namespace anvildev\beacon\services\ai;

/**
 * Provider-agnostic seam for a single synchronous LLM chat/completion call.
 *
 * v1 is request/response only. A streaming transport can implement this same
 * interface later (yielding via a callback option) without touching the
 * {@see \anvildev\beacon\services\AiContentService} callers.
 */
interface AiProviderInterface
{
    /**
     * Run one completion and return the model's text answer (trimmed).
     *
     * @param array<string,mixed> $options provider hints, e.g. `maxTokens`
     * @throws AiException on transport failure, non-2xx status, or empty body
     */
    public function complete(string $system, string $user, array $options = []): string;
}
