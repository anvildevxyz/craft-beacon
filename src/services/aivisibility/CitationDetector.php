<?php

namespace anvildev\beacon\services\aivisibility;

/**
 * Pure, DB-free detection of whether an answer-engine response cites or
 * mentions a site (and which competitors it names). Kept separate from
 * {@see \anvildev\beacon\services\AiVisibilityService} so the matching rules
 * are unit-testable without a booted Craft app or network.
 *
 * Two distinct signals, per the GEO playbook:
 *  - **URL citation** — the answer links to a URL on one of the site's hosts.
 *  - **Domain/brand mention** — the answer names the host in prose without a link.
 */
final class CitationDetector
{
    /**
     * @param list<string> $siteHosts hostnames owned by the site, e.g. `['acme.com', 'docs.acme.com']`
     * @param list<string> $competitorDomains competitor hostnames to watch for
     * @return array{cited: bool, matchedUrls: list<string>, domainMentioned: bool, competitorMentions: list<string>}
     */
    public function detect(string $answer, array $siteHosts, array $competitorDomains): array
    {
        $hosts = $this->normalizeHosts($siteHosts);

        $matchedUrls = [];
        foreach ($this->extractUrls($answer) as $url) {
            $host = $this->hostOf($url);
            if ($host !== null && $this->hostMatchesAny($host, $hosts)) {
                $matchedUrls[] = $url;
            }
        }
        $matchedUrls = array_values(array_unique($matchedUrls));

        $haystack = mb_strtolower($answer);

        $domainMentioned = false;
        foreach ($hosts as $host) {
            if ($host !== '' && str_contains($haystack, $host)) {
                $domainMentioned = true;
                break;
            }
        }

        $competitorMentions = [];
        foreach ($this->normalizeHosts($competitorDomains) as $domain) {
            if ($domain !== '' && str_contains($haystack, $domain)) {
                $competitorMentions[] = $domain;
            }
        }
        $competitorMentions = array_values(array_unique($competitorMentions));

        return [
            'cited' => $matchedUrls !== [],
            'matchedUrls' => $matchedUrls,
            'domainMentioned' => $domainMentioned,
            'competitorMentions' => $competitorMentions,
        ];
    }

    /**
     * @return list<string>
     */
    public function extractUrls(string $text): array
    {
        if (preg_match_all('#https?://[^\s<>()\[\]"\']+#i', $text, $m) === false) {
            return [];
        }
        // Trim trailing punctuation that commonly clings to URLs in prose.
        $urls = array_map(static fn(string $u): string => rtrim($u, '.,;:!?'), $m[0]);
        return array_values(array_filter($urls, static fn(string $u): bool => $u !== ''));
    }

    private function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        return $this->normalizeHost($host);
    }

    /**
     * A request host matches when it equals an owned host or is a subdomain of it
     * (so `www.acme.com` and `blog.acme.com` both match an owned `acme.com`).
     *
     * @param list<string> $hosts
     */
    private function hostMatchesAny(string $host, array $hosts): bool
    {
        foreach ($hosts as $owned) {
            if ($owned !== '' && ($host === $owned || str_ends_with($host, '.' . $owned))) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<string> $hosts
     * @return list<string>
     */
    private function normalizeHosts(array $hosts): array
    {
        $out = [];
        foreach ($hosts as $host) {
            $clean = $this->normalizeHost($host);
            if ($clean !== '') {
                $out[] = $clean;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Lowercase, strip scheme/path if a full URL slipped in, drop a leading
     * `www.`, and trim trailing dots/slashes.
     */
    private function normalizeHost(string $host): string
    {
        $host = trim(mb_strtolower($host));
        if ($host === '') {
            return '';
        }
        if (str_contains($host, '://')) {
            $parsed = parse_url($host, PHP_URL_HOST);
            $host = is_string($parsed) ? $parsed : $host;
        }
        // If a bare "domain.com/path" was passed, keep only the host part.
        $host = explode('/', $host)[0];
        $host = trim($host, ". \t");
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        return $host;
    }
}
