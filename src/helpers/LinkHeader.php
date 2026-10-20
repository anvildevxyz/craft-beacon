<?php

namespace anvildev\beacon\helpers;

use yii\web\Response;

/**
 * Combines one or more link relations into a single `Link:` response header
 * (RFC 8288 allows multiple link-values in one header field, comma-separated).
 * Every URL is stripped of CR/LF/NUL before use, so an editor-supplied value
 * (canonical, markdown alternate) can't inject extra header lines. `<` and
 * `>` are stripped too — both are excluded from RFC 3986 URIs (must be
 * percent-encoded if genuinely part of the URL), so a raw one only ever
 * means a malformed/adversarial value trying to break out of the `<...>`
 * delimiter that wraps the URL in the header value.
 */
final class LinkHeader
{
    /**
     * Replaces the response's `Link` header with the given relations. Entries
     * with a blank URL are skipped; if none remain, no header is set (an
     * existing `Link` header, if any, is left untouched).
     *
     * @param list<array{url:string, rel:string, type?:string}> $links
     */
    public static function apply(Response $response, array $links): void
    {
        $parts = [];
        foreach ($links as $link) {
            $url = str_replace(["\0", '<', '>'], '', Strings::stripLineBreaks($link['url']));
            if ($url === '') {
                continue;
            }
            $value = '<' . $url . '>; rel="' . $link['rel'] . '"';
            if (isset($link['type']) && $link['type'] !== '') {
                $value .= '; type="' . $link['type'] . '"';
            }
            $parts[] = $value;
        }
        if ($parts === []) {
            return;
        }
        $response->headers->set('Link', implode(', ', $parts));
    }
}
