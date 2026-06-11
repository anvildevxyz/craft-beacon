<?php

namespace anvildev\beacon\helpers;

use Craft;
use DateTime;
use yii\web\Request;
use yii\web\Response;

/**
 * Builds a raw (un-rendered) HTTP response with content-type and Cache-Control
 * already set. Centralises the "txt-like endpoint" tail repeated across the
 * sitemap/robots/llms/humans/ads/geo controllers.
 *
 * Always emits a strong ETag (sha256 of body) and honours `If-None-Match` /
 * `If-Modified-Since` with a 304 response when the client already has the
 * current representation.
 */
final class RawResponse
{
    /**
     * @param list<string> $cacheTags Tag identifiers (already kind+site-prefixed by caller) to emit
     *   as CDN surrogate-cache headers. Empty list = no surrogate headers emitted, preserving
     *   prior behaviour for callers that haven't opted in. We emit both `Cache-Tag` (Cloudflare)
     *   and `Surrogate-Key` (Fastly / Akamai) because operators run mixed CDN stacks and the
     *   header formats are otherwise unambiguous to translate at the edge.
     */
    public static function build(
        string $contentType,
        string $body,
        int $maxAge = 300,
        ?DateTime $lastModified = null,
        ?string $etag = null,
        array $cacheTags = [],
    ): Response {
        $appResponse = Craft::$app->getResponse();
        $response = $appResponse instanceof Response ? $appResponse : new Response();
        $response->format = Response::FORMAT_RAW;

        $h = $response->headers;
        $h->set('Content-Type', $contentType);
        $h->set('Cache-Control', "public, max-age={$maxAge}, stale-while-revalidate=86400");
        if ($lastModified !== null) {
            $h->set('Last-Modified', gmdate('D, d M Y H:i:s', $lastModified->getTimestamp()) . ' GMT');
        }

        $etag ??= '"' . hash('sha256', $body) . '"';
        $h->set('ETag', $etag);

        if ($cacheTags !== []) {
            $clean = array_values(array_unique(array_filter($cacheTags, static fn($t) => is_string($t) && $t !== '')));
            if ($clean !== []) {
                $h->set('Cache-Tag', implode(', ', $clean));
                $h->set('Surrogate-Key', implode(' ', $clean));
            }
        }

        $request = Craft::$app->getRequest();
        if ($request instanceof Request && self::isNotModified($request, $etag, $lastModified)) {
            $response->setStatusCode(304);
            $response->content = '';
            return $response;
        }

        $response->content = $body;
        return $response;
    }

    private static function isNotModified(Request $request, string $etag, ?DateTime $lastModified): bool
    {
        $ifNoneMatch = $request->getHeaders()->get('If-None-Match');
        if (is_string($ifNoneMatch) && $ifNoneMatch !== '') {
            // RFC 7232: when If-None-Match is present, If-Modified-Since MUST be ignored.
            foreach (explode(',', $ifNoneMatch) as $tag) {
                $tag = trim($tag);
                if ($tag === '*' || $tag === $etag) {
                    return true;
                }
                // Weak validator: strip leading `W/` and re-compare.
                if (str_starts_with($tag, 'W/') && substr($tag, 2) === $etag) {
                    return true;
                }
            }
            return false;
        }

        if ($lastModified !== null) {
            $ifModifiedSince = $request->getHeaders()->get('If-Modified-Since');
            if (is_string($ifModifiedSince) && $ifModifiedSince !== '') {
                $since = strtotime($ifModifiedSince);
                if ($since !== false && $lastModified->getTimestamp() <= $since) {
                    return true;
                }
            }
        }

        return false;
    }
}
