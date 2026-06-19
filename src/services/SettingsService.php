<?php

namespace anvildev\beacon\services;

use anvildev\beacon\helpers\Db;
use anvildev\beacon\helpers\Json;
use anvildev\beacon\models\Settings;
use anvildev\beacon\records\SettingsRecord;
use yii\base\Component;

class SettingsService extends Component
{
    private ?Settings $cached = null;

    /**
     * Power-user knobs that admins set via `config/beacon.php` rather than
     * the CP. Lookups follow: config-file value > DB value > Settings model
     * default. Listed here so the layering is auditable in one place.
     *
     * @var list<string>
     */
    private const CONFIG_FILE_OVERRIDES = [
        'botLogRetentionDays',
        'metaCacheDuration',
        'geoMarkdownBodyFieldHandle',
        'geoMarkdownExcerptLength',
        'geoMarkdownFrontMatterDefaults',
        'geoMarkdownSectionAllowlist',
        'geoMarkdownFullPageRender',
        'geoMarkdownExcludedClasses',
        'geoScoreSectionAllowlist',
        'geoScoreContentRenderMode',
        'geoScoreFactDensityTarget',
        'geoScoreAuthorityDomainOverrides',
        'socialImageTransform',
        'hreflangEnabled',
        'hreflangXDefaultSiteHandle',
        'indexNowEnabled',
        'log404s',
        'log404RetentionDays',
        'staleThresholdDays',
        'seoFieldLiteMode',
        // AI provider config — secret key + model belong in config/beacon.php in prod.
        'aiApiKey',
        'aiBaseUrl',
        'aiModel',
        'aiProvider',
    ];

    /**
     * Returns the plugin's global settings, memoized for the request. When no
     * settings row exists yet, returns defaults with config-file overrides applied.
     */
    public function get(): Settings
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $record = SettingsRecord::findOne(1);
        if ($record === null) {
            return $this->cached = $this->applyConfigFileOverrides(new Settings());
        }

        $settings = new Settings(
            titleTemplate: (string) $record->titleTemplate,
            descriptionTemplate: is_string($record->descriptionTemplate) ? $record->descriptionTemplate : '',
            organizationName: $record->organizationName,
            organizationLogoAssetId: $record->organizationLogoAssetId !== null ? (int) $record->organizationLogoAssetId : null,
            organizationImageAssetId: $record->organizationImageAssetId !== null ? (int) $record->organizationImageAssetId : null,
            socialProfiles: $this->decodeSocialProfiles($record->socialProfiles ?? null),
            identityType: $this->normalizeIdentityType($record->identityType ?? null),
            identityAdvanced: $this->decodeIdentityAdvanced($record->identityAdvanced ?? null),
            sectionSeoDefaults: $this->decodeSectionSeoDefaults($record->sectionSeoDefaults),
            metaCacheDuration: $this->normalizeMetaCacheDuration($record->metaCacheDuration ?? null),
            staleThresholdDays: (int) $record->staleThresholdDays,
            botLogRetentionDays: (int) $record->botLogRetentionDays,
            // File-only setting (config/beacon.php); applyConfigFileOverrides() applies any override.
            socialImageTransform: 'beaconSocial',
            defaultSocialImageId: $record->defaultSocialImageId !== null ? (int) $record->defaultSocialImageId : null,
            hreflangEnabled: (bool) $record->hreflangEnabled,
            hreflangXDefaultSiteHandle: ($record->hreflangXDefaultSiteHandle !== null && $record->hreflangXDefaultSiteHandle !== '') ? (string) $record->hreflangXDefaultSiteHandle : null,
            geoMarkdownEnabled: (bool) $record->geoMarkdownEnabled,
            geoMarkdownBodyFieldHandle: (is_string($record->geoMarkdownBodyFieldHandle) && $record->geoMarkdownBodyFieldHandle !== '') ? $record->geoMarkdownBodyFieldHandle : 'body',
            geoMarkdownNegotiateAcceptHeader: (bool) $record->geoMarkdownNegotiateAcceptHeader,
            geoMarkdownMdSuffixEnabled: (bool) $record->geoMarkdownMdSuffixEnabled,
            geoMarkdownExcerptFallbackToDescription: (bool) ($record->geoMarkdownExcerptFallbackToDescription ?? true),
            geoMarkdownAutoServeBots: (bool) ($record->geoMarkdownAutoServeBots ?? true),
            geoProvenanceSchemaEnabled: (bool) ($record->geoProvenanceSchemaEnabled ?? true),
            robotsDirectivesEnabled: $this->decodeRobotsDirectivesEnabled($record->robotsDirectivesEnabled ?? null),
            indexNowEnabled: (bool) ($record->indexNowEnabled ?? false),
            authorPagesEnabled: (bool) $record->authorPagesEnabled,
            authorPagesUriPrefix: $this->normalizeAuthorPagesUriPrefix($record->authorPagesUriPrefix),
            geoScoreEnabled: (bool) $record->geoScoreEnabled,
            geoScorePillarWeights: $this->decodePillarWeights($record->geoScorePillarWeights),
            geoScoreClaimDetectionMode: (string) $record->geoScoreClaimDetectionMode,
            geoScoreFactDetectionMode: (string) $record->geoScoreFactDetectionMode,
            // File-only; not stored in DB. Default true (lite SEO field UI).
            seoFieldLiteMode: true,
            aiEnabled: (bool) ($record->aiEnabled ?? false),
            aiProvider: (is_string($record->aiProvider) && $record->aiProvider !== '') ? $record->aiProvider : 'anthropic',
            aiModel: (string) ($record->aiModel ?? ''),
            aiApiKey: ($record->aiApiKey !== null && $record->aiApiKey !== '') ? (string) $record->aiApiKey : null,
            aiBaseUrl: ($record->aiBaseUrl !== null && $record->aiBaseUrl !== '') ? (string) $record->aiBaseUrl : null,
        );

        return $this->cached = $this->applyConfigFileOverrides($settings);
    }

    /**
     * Reads `config/beacon.php` (if present) and copies whitelisted keys onto
     * the live Settings model. The file is plain PHP returning an associative
     * array; we only honour keys in CONFIG_FILE_OVERRIDES so the file can't
     * accidentally override DB-managed properties like organization identity.
     */
    private function applyConfigFileOverrides(Settings $settings): Settings
    {
        if (!class_exists(\Craft::class) || \Craft::$app === null) {
            return $settings;
        }
        $config = \Craft::$app->getConfig()->getConfigFromFile('beacon');
        if (!is_array($config) || $config === []) {
            return $settings;
        }
        foreach (self::CONFIG_FILE_OVERRIDES as $key) {
            if (array_key_exists($key, $config)) {
                $settings->{$key} = $config[$key];
            }
        }
        return $this->clampBounds($settings);
    }

    /**
     * Enforce the documented numeric/enum bounds that the plain Settings DTO
     * can't validate on its own. Applied to every resolved Settings (record,
     * config-override, and rowless-default paths all funnel through
     * applyConfigFileOverrides), so an out-of-range config value or stale DB
     * value can't silently mis-score.
     */
    private function clampBounds(Settings $settings): Settings
    {
        // "1 fact per N words" target — documented bounds 30–400 (config.php).
        $settings->geoScoreFactDensityTarget = max(30, min(400, $settings->geoScoreFactDensityTarget));
        // 'llm' mode is not implemented; coerce to heuristic.
        $settings->geoScoreClaimDetectionMode = $this->normalizeDetectionMode($settings->geoScoreClaimDetectionMode);
        $settings->geoScoreFactDetectionMode = $this->normalizeDetectionMode($settings->geoScoreFactDetectionMode);
        return $settings;
    }

    /**
     * Detection modes currently implemented. Add 'llm' here once that path ships.
     *
     * @var list<string>
     */
    private const IMPLEMENTED_DETECTION_MODES = ['heuristic'];

    private function normalizeDetectionMode(string $mode): string
    {
        return in_array($mode, self::IMPLEMENTED_DETECTION_MODES, true) ? $mode : 'heuristic';
    }

    /**
     * @param array<mixed, mixed>|string|null $raw
     * @return array<string,float>
     */
    private function decodePillarWeights(array|string|null $raw): array
    {
        $decoded = Json::decodeAssoc($raw);
        if ($decoded === null) {
            return [];
        }
        $out = [];
        foreach ($decoded as $handle => $weight) {
            if (is_string($handle) && is_numeric($weight)) {
                $out[$handle] = (float) $weight;
            }
        }
        return $out;
    }

    /**
     * @param array<mixed, mixed>|string|null $raw
     * @return array<string,bool>|null
     */
    private function decodeRobotsDirectivesEnabled(array|string|null $raw): ?array
    {
        $decoded = Json::decodeAssoc($raw);
        if ($decoded === null) {
            return null;
        }
        $clean = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $clean[$key] = (bool) $value;
            }
        }
        return $clean;
    }

    /**
     * @return array<string,mixed>
     */
    public function getGeoDefaults(): array
    {
        return $this->get()->toGeoDefaults();
    }

    /**
     * Persists the global settings to the singleton row and refreshes the
     * request-level cache so subsequent get() calls return the saved values.
     */
    public function save(Settings $settings): void
    {
        $record = SettingsRecord::findOne(1) ?? new SettingsRecord(['id' => 1]);
        $record->titleTemplate = $settings->titleTemplate;
        $record->descriptionTemplate = $settings->descriptionTemplate;
        $record->organizationName = $settings->organizationName;
        $record->organizationLogoAssetId = $settings->organizationLogoAssetId;
        $record->organizationImageAssetId = $settings->organizationImageAssetId;
        $record->socialProfiles = Json::encode((object) $settings->socialProfiles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $record->identityType = $this->normalizeIdentityType($settings->identityType);
        $record->identityAdvanced = Json::encode($settings->identityAdvanced);
        $record->sectionSeoDefaults = Json::encode($settings->sectionSeoDefaults);
        $record->metaCacheDuration = $this->normalizeMetaCacheDuration($settings->metaCacheDuration);
        $record->staleThresholdDays = $settings->staleThresholdDays;
        $record->botLogRetentionDays = $settings->botLogRetentionDays;
        $record->defaultSocialImageId = $settings->defaultSocialImageId;
        $record->hreflangEnabled = $settings->hreflangEnabled;
        $record->hreflangXDefaultSiteHandle = $settings->hreflangXDefaultSiteHandle;
        $record->geoMarkdownEnabled = $settings->geoMarkdownEnabled;
        $record->geoMarkdownBodyFieldHandle = $settings->geoMarkdownBodyFieldHandle;
        $record->geoMarkdownNegotiateAcceptHeader = $settings->geoMarkdownNegotiateAcceptHeader;
        $record->geoMarkdownMdSuffixEnabled = $settings->geoMarkdownMdSuffixEnabled;
        $record->geoMarkdownExcerptFallbackToDescription = $settings->geoMarkdownExcerptFallbackToDescription;
        $record->geoMarkdownAutoServeBots = $settings->geoMarkdownAutoServeBots;
        $record->geoProvenanceSchemaEnabled = $settings->geoProvenanceSchemaEnabled;
        $record->robotsDirectivesEnabled = $settings->robotsDirectivesEnabled === null
            ? null
            : Json::encode($settings->robotsDirectivesEnabled);
        $record->indexNowEnabled = $settings->indexNowEnabled;
        $record->authorPagesEnabled = $settings->authorPagesEnabled;
        $record->authorPagesUriPrefix = $this->normalizeAuthorPagesUriPrefix($settings->authorPagesUriPrefix);
        $record->geoScoreEnabled = $settings->geoScoreEnabled;
        $record->geoScorePillarWeights = $settings->geoScorePillarWeights === []
            ? null
            : Json::encode($settings->geoScorePillarWeights);
        $record->geoScoreClaimDetectionMode = $this->normalizeDetectionMode($settings->geoScoreClaimDetectionMode);
        $record->geoScoreFactDetectionMode = $this->normalizeDetectionMode($settings->geoScoreFactDetectionMode);
        $record->aiEnabled = $settings->aiEnabled;
        $record->aiProvider = $settings->aiProvider;
        $record->aiModel = $settings->aiModel;
        $record->aiApiKey = $settings->aiApiKey;
        $record->aiBaseUrl = $settings->aiBaseUrl;
        $record->dateUpdated = Db::now();
        $record->save(false);

        $this->cached = $settings;
    }

    /**
     * @param array<mixed, mixed>|string|null $raw
     * @return array<string,array<string,string>>
     */
    private function decodeSectionSeoDefaults(array|string|null $raw): array
    {
        $decoded = Json::decodeAssoc($raw);
        if ($decoded === null) {
            return [];
        }

        $clean = [];
        foreach ($decoded as $sectionHandle => $row) {
            if (!is_string($sectionHandle) || !is_array($row)) {
                continue;
            }
            $titleTemplate = trim((string) ($row['titleTemplate'] ?? ''));
            $descriptionTemplate = trim((string) ($row['descriptionTemplate'] ?? ''));
            if ($titleTemplate === '' && $descriptionTemplate === '') {
                continue;
            }
            $clean[$sectionHandle] = [
                'titleTemplate' => $titleTemplate,
                'descriptionTemplate' => $descriptionTemplate,
            ];
        }

        return $clean;
    }

    /**
     * Trim slashes/whitespace and fall back to 'authors' when blank — guarantees
     * AuthorElement::getUriFormat() always has a usable, slash-free prefix.
     */
    private function normalizeAuthorPagesUriPrefix(string|int|float|bool|null $value): string
    {
        $clean = trim((string) ($value ?? ''), "/ \t\n\r\0\x0B");
        return $clean !== '' ? $clean : 'authors';
    }

    private function normalizeIdentityType(string|int|float|bool|null $value): string
    {
        return \anvildev\beacon\helpers\IdentityTypes::normalize(trim((string) $value));
    }

    /**
     * Reads the JSON map persisted in `beacon_settings.socialProfiles` and
     * filters it down to known platform keys with non-empty string URLs.
     *
     * @param array<mixed, mixed>|string|null $raw
     * @return array<string,string>
     */
    private function decodeSocialProfiles(array|string|null $raw): array
    {
        $decoded = Json::decodeAssoc($raw);
        if ($decoded === null) {
            return [];
        }
        $known = \anvildev\beacon\helpers\SocialPlatforms::keys();
        $clean = [];
        foreach ($known as $key) {
            $value = $decoded[$key] ?? null;
            if (is_string($value) && ($trimmed = trim($value)) !== '') {
                $clean[$key] = $trimmed;
            }
        }
        return $clean;
    }

    /**
     * @param array<mixed, mixed>|string|null $raw
     * @return array<string,mixed>
     */
    private function decodeIdentityAdvanced(array|string|null $raw): array
    {
        /** @var array<string, mixed> */
        return Json::decodeAssoc($raw) ?? [];
    }

    private function normalizeMetaCacheDuration(string|int|float|bool|null $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }
        $int = (int) $value;
        return $int > 0 ? $int : null;
    }
}
