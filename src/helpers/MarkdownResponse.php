<?php

namespace anvildev\beacon\helpers;

use DateTime;
use yii\web\Response;

/**
 * Markdown-specific response helper. Wraps {@see RawResponse::build()} and
 * layers the headers expected on every Beacon Markdown surface:
 *
 * - `Vary: Accept` — the URL has multiple representations (HTML and MD).
 *   When `$includeUserAgentInVary` is true (bot auto-serve path), `User-Agent`
 *   joins the list so caches don't conflate bot vs. browser responses.
 * - `X-Robots-Tag: noindex, nofollow` — the canonical HTML page is the
 *   indexable representation; the Markdown copy must not compete in SERPs.
 * - `Link: <canonical>; rel="canonical"` — explicit pointer back to the HTML
 *   for clients that respect Link headers (most search engines do).
 */
final class MarkdownResponse
{
    public static function build(
        string $body,
        ?string $canonicalUrl,
        ?DateTime $lastModified = null,
        int $maxAge = 120,
        bool $includeUserAgentInVary = false,
    ): Response {
        $response = RawResponse::build('text/markdown; charset=UTF-8', $body, $maxAge, $lastModified);
        self::applyHeaders($response, $canonicalUrl, $includeUserAgentInVary);
        return $response;
    }

    /**
     * Inline equivalent for the negotiator: it doesn't return the response,
     * it injects directly into the running response. Sets the same three
     * headers; body, content-type, and conditional-response handling happen
     * in the calling context via {@see RawResponse::build()}.
     */
    public static function applyHeaders(
        Response $response,
        ?string $canonicalUrl,
        bool $includeUserAgentInVary = false,
    ): void {
        $h = $response->headers;
        $h->set('Vary', $includeUserAgentInVary ? 'Accept, User-Agent' : 'Accept');
        $h->set('X-Robots-Tag', 'noindex, nofollow');
        if ($canonicalUrl !== null && $canonicalUrl !== '') {
            $h->set('Link', "<{$canonicalUrl}>; rel=\"canonical\"");
        }
    }
}
