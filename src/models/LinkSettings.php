<?php

namespace anvildev\beacon\models;

use craft\base\Model;

/**
 * Settings for the Links (internal-link-graph) feature.
 *
 * Stored as a single row in `{{%beacon_link_settings}}` and loaded via
 * {@see \anvildev\beacon\services\Links::getSettings()}, which layers
 * `config/beacon.php` overrides (the `links` sub-array) over the DB row over
 * these defaults. Kept distinct from the global {@see Settings} model so the
 * feature stays self-contained, mirroring Beacon's other per-feature settings
 * tables.
 *
 * Embeddings reuse Beacon's {@see \anvildev\beacon\services\AiClient}: only a
 * model id is required; `embeddingsApiKey` / `embeddingsBaseUrl` fall back to
 * the global AI provider config when blank.
 */
class LinkSettings extends Model
{
    /** Master switch — when false, indexing, sidebar, reports, and Twig helpers are off. */
    public bool $enabled = true;

    /** @var list<string> Section handles eligible for indexing. Empty = all. */
    public array $enabledSections = [];
    public int $maxKeywordsPerEntry = 50;
    public string $stopWordsLanguage = 'en';
    public int $minKeywordLength = 3;
    public bool $indexOnSave = true;
    public bool $showSidebarSuggestions = true;
    public int $maxSuggestions = 10;
    public float $minScore = 0.1;
    public float $maxDocumentFrequencyRatio = 0.6;
    public bool $excludeSameSection = false;
    public bool $embeddingsEnabled = false;
    /** OpenAI-compatible host (no path); blank falls back to the global AI base URL. */
    public string $embeddingsBaseUrl = '';
    public string $embeddingsApiKey = '';
    public string $embeddingsModel = 'text-embedding-3-small';
    public int $reportCacheDuration = 3600;
    public int $autoReindexInterval = 0;
    public int $httpAuditTimeout = 10;
    public int $httpAuditDelay = 200;
    /** @var list<string> Anchor phrases flagged as non-descriptive in the anchor-text report. */
    public array $genericAnchorPatterns = [
        'click here', 'read more', 'learn more', 'here', 'link', 'this', 'more info',
        'more', 'details', 'info', 'see more', 'find out more', 'go', 'continue',
    ];

    /**
     * Hide embeddingsApiKey from default serialization (toArray, JSON, caching)
     * to prevent accidental secret leakage. Read $settings->embeddingsApiKey
     * directly when the value is genuinely needed.
     *
     * @return array<int|string, string|\Closure>
     */
    public function fields(): array
    {
        $fields = parent::fields();
        unset($fields['embeddingsApiKey']);
        return $fields;
    }

    /**
     * @return list<string>
     */
    public function extraFields(): array
    {
        return ['embeddingsApiKey'];
    }

    /**
     * @return array<int, array<int|string, mixed>>
     */
    protected function defineRules(): array
    {
        return [
            [['enabledSections'], 'each', 'rule' => ['string']],
            [['maxKeywordsPerEntry'], 'integer', 'min' => 1],
            [['stopWordsLanguage'], 'in', 'range' => ['en', 'de', 'es', 'fr', 'it', 'ja', 'nl', 'pt']],
            [['minKeywordLength'], 'integer', 'min' => 1],
            [['enabled', 'indexOnSave', 'showSidebarSuggestions', 'excludeSameSection', 'embeddingsEnabled'], 'boolean'],
            [['maxSuggestions'], 'integer', 'min' => 1],
            [['minScore'], 'number', 'min' => 0],
            [['maxDocumentFrequencyRatio'], 'number', 'min' => 0.1, 'max' => 1.0],
            [['embeddingsBaseUrl'], 'url', 'defaultScheme' => 'https', 'when' => fn($model) => $model->embeddingsBaseUrl !== ''],
            [['embeddingsApiKey', 'embeddingsModel'], 'string'],
            [['embeddingsModel'], 'required', 'when' => fn($model) => $model->embeddingsApiKey !== '' && $model->embeddingsEnabled, 'message' => 'Model is required when embeddings are enabled with an API key.'],
            [['reportCacheDuration'], 'integer', 'min' => 0],
            [['autoReindexInterval', 'httpAuditTimeout', 'httpAuditDelay'], 'integer', 'min' => 0],
            [['genericAnchorPatterns'], 'each', 'rule' => ['string']],
        ];
    }
}
