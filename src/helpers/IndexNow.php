<?php

namespace anvildev\beacon\helpers;

/**
 * Pure IndexNow submit helpers — URL normalization, payload shape, and
 * HTTP status classification extracted from {@see \anvildev\beacon\services\IndexNowService}
 * so they can be unit-tested without Guzzle or Craft bootstrap.
 */
final class IndexNow
{
    /**
     * @param array<mixed> $urls
     * @return list<string>
     */
    public static function normalizeUrls(array $urls): array
    {
        return array_values(array_unique(array_filter(
            $urls,
            static fn($u): bool => is_string($u) && $u !== '',
        )));
    }

    /**
     * @param list<string> $urls
     * @return array{host:string, key:string, keyLocation:string, urlList:list<string>}
     */
    public static function buildPayload(string $host, string $key, string $baseUrl, array $urls): array
    {
        return [
            'host' => $host,
            'key' => $key,
            'keyLocation' => rtrim($baseUrl, '/') . '/' . $key . '.txt',
            'urlList' => $urls,
        ];
    }

    public static function isSuccessStatus(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    public static function rejectionNote(string $body, int $maxLen = 200): string
    {
        return sprintf('rejected — body=%s', substr($body, 0, $maxLen));
    }
}
