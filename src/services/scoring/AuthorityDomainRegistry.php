<?php

namespace anvildev\beacon\services\scoring;

use anvildev\beacon\Plugin;
use anvildev\beacon\services\scoring\heuristics\DomainMatcher;
use Craft;
use Throwable;

/**
 * @phpstan-import-type AuthorityDomainOverride from \anvildev\beacon\models\Settings
 * Owns the authority-domain list consumed by
 * {@see OutboundCitationDensityPillar}. Loads the bundled defaults from
 * `src/data/authority-domains.json` and merges operator overrides from
 * {@see \anvildev\beacon\models\Settings::$geoScoreAuthorityDomainOverrides}.
 *
 * Override shape:
 *
 *   [
 *     ['domain' => 'example.org', 'tier' => 2, 'enabled' => true],   // ADD
 *     ['domain' => 'wikipedia.org', 'enabled' => false],             // DISABLE default
 *   ]
 *
 * The classifier resolves to:
 *
 *   tier 1 → authoritative, weight 1.0 in citation density.
 *   tier 2 → curated publisher, weight 0.6.
 *   null   → unclassified outbound link; counts toward fact density but not
 *            authority density.
 *
 * @phpstan-type AuthorityEntry array{domain: string, tier: int}
 */
final class AuthorityDomainRegistry
{
    /** @var list<AuthorityEntry>|null */
    private ?array $cached = null;

    /**
     * @param list<array<string,mixed>>|null $overridesForTest If non-null,
     *   bypasses the settings read in {@see self::loadOverrides()}. Intended
     *   for unit tests so the registry can run without a Plugin bootstrap.
     */
    public function __construct(
        private readonly DomainMatcher $matcher = new DomainMatcher(),
        private readonly ?string $bundlePath = null,
        private readonly ?array $overridesForTest = null,
    ) {
    }

    /**
     * Resolve a hostname to its tier (1 or 2), or null when not authoritative.
     */
    public function classify(string $host): ?int
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return null;
        }
        foreach ($this->entries() as $entry) {
            if ($this->matcher->matchesOne($host, $entry['domain'])) {
                return $entry['tier'];
            }
        }
        return null;
    }

    /**
     * Drop the in-memory cache. Called by SettingsController when the
     * overrides change so the next classify() picks up the new list.
     */
    public function invalidate(): void
    {
        $this->cached = null;
    }

    /**
     * @return list<array{domain: string, tier: int, addedAt?: string, source?: string}>
     */
    public function defaults(): array
    {
        $raw = $this->loadBundle();
        $entries = [];
        foreach ($raw['domains'] ?? [] as $row) {
            if (!is_array($row) || !isset($row['domain'], $row['tier'])) {
                continue;
            }
            $domain = strtolower(trim((string) $row['domain']));
            $tier = (int) $row['tier'];
            if ($domain === '' || !in_array($tier, [1, 2], true)) {
                continue;
            }
            $entries[] = [
                'domain' => $domain,
                'tier' => $tier,
                'addedAt' => (string) ($row['addedAt'] ?? ''),
                'source' => (string) ($row['source'] ?? ''),
            ];
        }
        return $entries;
    }

    /**
     * Build the final list = bundled defaults minus disabled, plus operator
     * additions. Returned in classification-priority order: operator
     * additions first (most specific to the operator's site), then bundled
     * defaults. Memoised per-instance.
     *
     * @return list<AuthorityEntry>
     */
    public function entries(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $overrides = $this->loadOverrides();
        $disabled = [];
        $additions = [];
        foreach ($overrides as $row) {
            $domain = $row['domain'];
            // Missing 'enabled' key means enabled by default.
            $enabled = !isset($row['enabled']) || (bool) $row['enabled'];
            if (!$enabled) {
                $disabled[$domain] = true;
                continue;
            }
            $tier = isset($row['tier']) ? (int) $row['tier'] : 2;
            if (!in_array($tier, [1, 2], true)) {
                $tier = 2;
            }
            $additions[] = ['domain' => $domain, 'tier' => $tier];
        }

        $merged = $additions;
        foreach ($this->defaults() as $default) {
            if (!isset($disabled[$default['domain']])) {
                $merged[] = ['domain' => $default['domain'], 'tier' => $default['tier']];
            }
        }

        return $this->cached = $merged;
    }

    /**
     * @return array{_meta?: array<string,mixed>, domains?: list<array<string,mixed>>}
     */
    private function loadBundle(): array
    {
        $path = $this->bundlePath ?? dirname(__DIR__, 2) . '/data/authority-domains.json';
        if (!is_file($path)) {
            return ['domains' => []];
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            return ['domains' => []];
        }
        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            Craft::warning('AuthorityDomainRegistry: failed to parse bundle: ' . $e->getMessage(), 'beacon');
            return ['domains' => []];
        }
        return is_array($decoded) ? $decoded : ['domains' => []];
    }

    /**
     * @return list<AuthorityDomainOverride>
     */
    protected function loadOverrides(): array
    {
        if ($this->overridesForTest !== null) {
            return $this->normalizeOverrides($this->overridesForTest);
        }
        if (Plugin::$plugin === null) {
            return [];
        }
        try {
            $settings = Plugin::$plugin->settings->get();
        } catch (Throwable) {
            // Justified: settings table may not exist yet during early bootstrap (pre-migration).
            return [];
        }
        return $this->normalizeOverrides($settings->geoScoreAuthorityDomainOverrides);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<AuthorityDomainOverride>
     */
    private function normalizeOverrides(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['domain'])) {
                continue;
            }
            $domain = strtolower(trim((string) $row['domain']));
            if ($domain === '') {
                continue;
            }
            $entry = ['domain' => $domain];
            if (isset($row['tier'])) {
                $entry['tier'] = (int) $row['tier'];
            }
            if (array_key_exists('enabled', $row)) {
                $entry['enabled'] = (bool) $row['enabled'];
            }
            $out[] = $entry;
        }
        return $out;
    }
}
