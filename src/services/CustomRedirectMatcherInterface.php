<?php

namespace anvildev\beacon\services;

/**
 * Implement this to register a custom redirect matching algorithm that
 * sits alongside Beacon's built-in `exact`, `glob`, and `regex` types.
 *
 * The handle is what stored rows put in their `type` column; the matcher
 * is called once per rule for each unhandled 404 (the service caches the
 * wildcard list per request, so cost is N rules × 1 call).
 *
 * Example use cases:
 *   - `legacy-id` — extract a numeric ID from `/article/<n>` and look it
 *     up against an entry custom field.
 *   - `slug-history` — match against any historical slug recorded on the
 *     entry, regardless of which one is current.
 *
 * @since 1.0.0
 *
 * @phpstan-type RedirectMatchResult array{captures:array<string,string>, query:string}|null
 */
interface CustomRedirectMatcherInterface
{
    /**
     * Lowercase, hyphenated handle stored in the `type` column.
     */
    public function handle(): string;

    /**
     * Human-readable label shown in the redirect edit form.
     */
    public function label(): string;

    /**
     * Returns capture groups (named or `$1`/`$2`/…) and the query string
     * that should be appended in `preserve` mode, or null on no match.
     *
     * @return RedirectMatchResult
     */
    public function match(string $pattern, string $uri): ?array;
}
