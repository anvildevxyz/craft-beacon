<?php

namespace anvildev\beacon\models;

/**
 * @phpstan-type IdentityAdvanced array<string, string|list<string>>
 * @phpstan-type AuthorityDomainOverride array{domain: string, tier?: int, enabled?: bool}
 * @phpstan-type GeoDefaults array{
 *     titleTemplate: string,
 *     descriptionTemplate: string,
 *     sectionSeoDefaults: array<string,array<string,string>>,
 *     metaCacheDuration: int|null,
 *     organization: array{
 *         name: string|null,
 *         logoAssetId: int|null,
 *         sameAs: list<string>,
 *         identityType: string,
 *         advanced: array<string, string|list<string>>,
 *     },
 *     socialImageTransform: string,
 *     defaultSocialImageId: int|null,
 *     defaultTwitterSite: string|null,
 *     aiUsagePolicy: string,
 *     aiUsagePolicyUrl: string|null,
 * }
 */
class Settings
{
    /**
     * @param array<string, mixed> $socialProfiles Map of platform key (twitter, facebook, etc.) → profile URL. Only known platforms in {@see \anvildev\beacon\helpers\SocialPlatforms::keys()} are honored.
     * @param array<string,array<string,string>> $sectionSeoDefaults
     * @param IdentityAdvanced $identityAdvanced Whitelisted Schema.org identity keys (alternateName, legalName, description, address parts, etc.). Scalar fields are stored as strings; the `founder`, `knowsAbout`, and `knowsLanguage` keys hold `list<string>`.
     * @param array<string,bool>|null $robotsDirectivesEnabled Per-directive opt-in for the SEO field. `null` (fresh install / no settings row) enables the default four: noindex / nofollow / noarchive / nosnippet.
     * @param list<string> $geoMarkdownExcludedClasses CSS class names whose elements are stripped before HTML→Markdown conversion.
     * @param array<string,scalar|null> $geoMarkdownFrontMatterDefaults Site-level front matter keys merged underneath section + entry data.
     * @param list<string> $geoScoreSectionAllowlist Section handles in which entries get a GEO score. Empty list = all sections.
     * @param array<string,float> $geoScorePillarWeights Per-pillar weight overrides, keyed by pillar handle. Unspecified pillars fall back to the defaults in {@see \anvildev\beacon\enums\GeoScorePillar::defaultWeight()}.
     * @param string $geoScoreContentRenderMode One of `''` (follow `$geoMarkdownFullPageRender`), `'bodyField'`, or `'fullRender'`. Drives how the structural-pillar content walker reads entry content. See {@see self::effectiveGeoScoreRenderMode()}.
     * @param string $geoScoreClaimDetectionMode `'heuristic'` (default) or `'llm'` (not implemented; coerced to heuristic at runtime).
     * @param int $geoScoreFactDensityTarget Target facts-per-word ratio (default 80 = one fact per 80 words).
     * @param string $geoScoreFactDetectionMode `'heuristic'` (default) or `'llm'` (not implemented; coerced to heuristic at runtime).
     * @param list<AuthorityDomainOverride> $geoScoreAuthorityDomainOverrides Operator overrides for the outbound-citation pillar's bundled authority list. Each row either adds a domain (with `tier` 1 or 2) or disables a bundled default (with `enabled: false`).
     */
    public function __construct(
        public string $titleTemplate = '{title}',
        public string $descriptionTemplate = '',
        public ?string $organizationName = null,
        public ?int $organizationLogoAssetId = null,
        public ?int $organizationImageAssetId = null,
        public array $socialProfiles = [],
        public string $identityType = 'Organization',
        public array $identityAdvanced = [],
        public array $sectionSeoDefaults = [],
        public ?int $metaCacheDuration = null,
        public int $staleThresholdDays = 90,
        public int $botLogRetentionDays = 30,
        public bool $log404s = true,
        public int $log404RetentionDays = 90,
        // Defaults to false to match the install migration column default and the
        // seeded settings row (hreflang is opt-in). Keep these two in sync.
        public bool $hreflangEnabled = false,
        public ?string $hreflangXDefaultSiteHandle = null,
        public bool $geoMarkdownEnabled = true,
        public string $geoMarkdownBodyFieldHandle = 'body',
        public bool $geoMarkdownNegotiateAcceptHeader = true,
        public bool $geoMarkdownMdSuffixEnabled = true,
        /** @var list<string> */
        public array $geoMarkdownSectionAllowlist = [],
        public ?int $geoMarkdownExcerptLength = null,
        public bool $geoMarkdownExcerptFallbackToDescription = true,
        public bool $geoMarkdownFullPageRender = true,
        public array $geoMarkdownExcludedClasses = [],
        public array $geoMarkdownFrontMatterDefaults = [],
        public bool $geoMarkdownAutoServeBots = true,
        public bool $geoProvenanceSchemaEnabled = true,
        public string $socialImageTransform = 'beaconSocial',
        public ?int $defaultSocialImageId = null,
        public ?array $robotsDirectivesEnabled = null,
        public bool $indexNowEnabled = false,
        public bool $authorPagesEnabled = false,
        public string $authorPagesUriPrefix = 'authors',
        public bool $geoScoreEnabled = true,
        public array $geoScoreSectionAllowlist = [],
        public array $geoScorePillarWeights = [],
        public string $geoScoreContentRenderMode = '',
        public string $geoScoreClaimDetectionMode = 'heuristic',
        public int $geoScoreFactDensityTarget = 80,
        public string $geoScoreFactDetectionMode = 'heuristic',
        public array $geoScoreAuthorityDomainOverrides = [],
        /** When true, the entry SEO field hides checklist, inheritance chrome, soft hints, and extra preview tabs. */
        public bool $seoFieldLiteMode = true,
        /** Master toggle for AI-assisted content generation. Dormant (no UI, no calls) when false. */
        public bool $aiEnabled = false,
        /** LLM provider: `anthropic` or `openai` (any OpenAI-compatible endpoint). */
        public string $aiProvider = 'anthropic',
        /** Provider model id, e.g. `claude-3-5-haiku-latest` or `gpt-4o-mini`. */
        public string $aiModel = '',
        /** Provider API key. Secret — set via `config/beacon.php` in production. */
        public ?string $aiApiKey = null,
        /** Optional base-URL override for self-hosted / gateway endpoints. */
        public ?string $aiBaseUrl = null,
        /** Master toggle for the answer-engine visibility/citation tracking panel. Dormant when false. */
        public bool $aiVisibilityEnabled = false,
        /**
         * Engine identifiers to probe. Empty = the single configured AI provider.
         * @var list<string>
         */
        public array $aiVisibilityEngines = [],
        /**
         * Competitor hostnames to watch for in answers (e.g. `['rival.com']`).
         * @var list<string>
         */
        public array $aiVisibilityCompetitorDomains = [],
        /** Hard cap on probes (prompts × engines) per run, to bound LLM cost. */
        public int $aiVisibilityMaxPerRun = 50,
        /** Days to retain visibility result rows before GC. */
        public int $aiVisibilityResultRetentionDays = 365,
        /** Scheduled cadence: `off`, `daily`, or `weekly`. */
        public string $aiVisibilityCadence = 'off',
        /** Global default AI-usage policy: `allow` (default) / `no-train` / `no-generative-ai` / `no-ai`. */
        public string $aiUsagePolicy = 'allow',
        /** Optional URL to a published AI-usage / licensing policy, emitted as TDMRep `tdm-policy`. */
        public ?string $aiUsagePolicyUrl = null,
        /** When true, the MCP server endpoint at `/beacon/mcp` accepts authenticated requests. Off by default. */
        public bool $mcpEnabled = false,
    ) {
    }

    /**
     * Resolved render mode for the GEO score content walker. Empty
     * `geoScoreContentRenderMode` follows `geoMarkdownFullPageRender` so
     * operators don't have to wire the same toggle in two places. Explicit
     * `'bodyField'` / `'fullRender'` always wins.
     */
    public function effectiveGeoScoreRenderMode(): string
    {
        return match ($this->geoScoreContentRenderMode) {
            'bodyField', 'fullRender' => $this->geoScoreContentRenderMode,
            default => $this->geoMarkdownFullPageRender ? 'fullRender' : 'bodyField',
        };
    }

    /**
     * @return GeoDefaults
     */
    public function toGeoDefaults(): array
    {
        return [
            'titleTemplate' => $this->titleTemplate,
            'descriptionTemplate' => $this->descriptionTemplate,
            'sectionSeoDefaults' => $this->sectionSeoDefaults,
            'metaCacheDuration' => $this->metaCacheDuration,
            'organization' => [
                'name' => $this->organizationName,
                'logoAssetId' => $this->organizationLogoAssetId,
                'sameAs' => $this->sameAsUrls(),
                'identityType' => $this->identityType,
                'advanced' => $this->identityAdvanced,
            ],
            'socialImageTransform' => $this->socialImageTransform,
            'defaultSocialImageId' => $this->defaultSocialImageId,
            'defaultTwitterSite' => $this->twitterSiteHandle(),
            'aiUsagePolicy' => $this->aiUsagePolicy,
            'aiUsagePolicyUrl' => $this->aiUsagePolicyUrl,
        ];
    }

    /**
     * All profile URLs (known platforms + custom extras) for Schema.org `sameAs`.
     *
     * @return list<string>
     */
    public function sameAsUrls(): array
    {
        $urls = array_filter(
            array_map(static fn(mixed $url): string => is_string($url) ? trim($url) : '', array_values($this->socialProfiles)),
            static fn(string $url): bool => $url !== '',
        );
        return array_values(array_unique($urls));
    }

    /**
     * Bare Twitter / X handle (no leading `@`) parsed from `socialProfiles['twitter']`.
     * Returns null when no Twitter URL is configured or the URL doesn't match a
     * recognised host.
     */
    public function twitterSiteHandle(): ?string
    {
        $url = $this->socialProfiles['twitter'] ?? null;
        if (!is_string($url) || trim($url) === '') {
            return null;
        }
        return \anvildev\beacon\helpers\SocialPlatforms::parseHandle('twitter', $url);
    }
}
