<?php

namespace anvildev\beacon\services\scoring\heuristics;

/**
 * Host → pattern matching with `*.` wildcard support. Shared by
 * {@see \anvildev\beacon\services\scoring\AuthorityDomainRegistry}
 * and anything else in the scoring layer that needs to test "does this
 * host belong to a named domain (or any of its subdomains)?"
 *
 * Matching rules:
 *
 *   - exact: `nytimes.com` matches `nytimes.com` only, not `www.nytimes.com`.
 *   - apex+sub: `*.wikipedia.org` matches `wikipedia.org`, `en.wikipedia.org`,
 *     `de.wikipedia.org`, `secure.en.wikipedia.org`. The apex *is* matched
 *     by a `*.` pattern — that's the more intuitive operator expectation
 *     than requiring two separate entries.
 *   - TLD-wildcard: `*.edu` matches `mit.edu`, `cs.mit.edu`, etc.
 *
 * Case-insensitive throughout (hostnames are ASCII-case-insensitive).
 */
final class DomainMatcher
{
    /**
     * @param list<string> $patterns
     */
    public function matches(string $host, array $patterns): bool
    {
        return $this->firstMatch($host, $patterns) !== null;
    }

    /**
     * Return the first matching pattern from the list (so the caller knows
     * *which* rule classified the host — useful for explainability in the
     * authority pillar's debug array).
     *
     * @param list<string> $patterns
     */
    public function firstMatch(string $host, array $patterns): ?string
    {
        $host = $this->normalise($host);
        if ($host === '') {
            return null;
        }
        foreach ($patterns as $pattern) {
            if ($this->matchesOne($host, $pattern)) {
                return $pattern;
            }
        }
        return null;
    }

    public function matchesOne(string $host, string $pattern): bool
    {
        $host = $this->normalise($host);
        $pattern = $this->normalise($pattern);
        if ($host === '' || $pattern === '') {
            return false;
        }

        if (str_starts_with($pattern, '*.')) {
            $apex = substr($pattern, 2);
            // `*.edu` matches `mit.edu`, `cs.mit.edu`, AND the bare apex `edu`
            // is too short to ever be a real host — but `*.wikipedia.org`
            // legitimately matches `wikipedia.org` itself.
            return $host === $apex || str_ends_with($host, '.' . $apex);
        }

        return $host === $pattern;
    }

    /**
     * Extract a hostname from any href shape — full URL, protocol-relative,
     * path-relative (returns empty), mailto/tel (empty).
     */
    public function hostFrom(string $href): string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#')) {
            return '';
        }
        // protocol-relative `//example.com/x` → parse_url returns no scheme
        // but does return the host. Plain `/foo` returns no host. mailto/tel
        // return no host.
        $host = parse_url($href, PHP_URL_HOST);
        return is_string($host) ? $this->normalise($host) : '';
    }

    private function normalise(string $value): string
    {
        return strtolower(trim($value));
    }
}
