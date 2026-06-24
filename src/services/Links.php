<?php

namespace anvildev\beacon\services;

use anvildev\beacon\helpers\Json;
use anvildev\beacon\models\LinkSettings;
use anvildev\beacon\records\LinkSettingsRecord;
use anvildev\beacon\services\links\AnchorTextService;
use anvildev\beacon\services\links\BrokenLinkService;
use anvildev\beacon\services\links\DepthService;
use anvildev\beacon\services\links\EmbeddingService;
use anvildev\beacon\services\links\ExportService;
use anvildev\beacon\services\links\IndexService;
use anvildev\beacon\services\links\LinkScanService;
use anvildev\beacon\services\links\ReportService;
use anvildev\beacon\services\links\SuggestionService;
use anvildev\beacon\services\links\TrendService;
use Craft;
use yii\base\Component;

/**
 * Facade for the Links (internal-link-graph) feature, registered as
 * `Plugin::$plugin->links`. Sub-services are exposed as lazily-instantiated
 * Yii component getters (e.g. `$plugin->links->suggestions`) so the ported
 * link code keeps Whisper's accessor names with a one-line rebase.
 *
 * Also owns the feature's settings: a single {@see LinkSettingsRecord} row,
 * layered under `config/beacon.php` `links` overrides, memoized per request.
 *
 * Backing fields are suffixed `*Service` so the magic property names
 * (`->index`, `->suggestions`, …) resolve to the Yii getters, not the private
 * fields — see the `@property-read` map.
 *
 * @property-read IndexService $index
 * @property-read LinkScanService $linkScan
 * @property-read BrokenLinkService $brokenLinks
 * @property-read AnchorTextService $anchorText
 * @property-read DepthService $depth
 * @property-read SuggestionService $suggestions
 * @property-read EmbeddingService $embedding
 * @property-read ReportService $reports
 * @property-read ExportService $export
 * @property-read TrendService $trends
 */
class Links extends Component
{
    private ?LinkSettings $settingsModel = null;

    private ?IndexService $indexService = null;
    private ?LinkScanService $linkScanService = null;
    private ?BrokenLinkService $brokenLinkService = null;
    private ?AnchorTextService $anchorTextService = null;
    private ?DepthService $depthService = null;
    private ?SuggestionService $suggestionService = null;
    private ?EmbeddingService $embeddingService = null;
    private ?ReportService $reportService = null;
    private ?ExportService $exportService = null;
    private ?TrendService $trendService = null;

    public function getIndex(): IndexService
    {
        return $this->indexService ??= new IndexService();
    }

    public function getLinkScan(): LinkScanService
    {
        return $this->linkScanService ??= new LinkScanService();
    }

    public function getBrokenLinks(): BrokenLinkService
    {
        return $this->brokenLinkService ??= new BrokenLinkService();
    }

    public function getAnchorText(): AnchorTextService
    {
        return $this->anchorTextService ??= new AnchorTextService();
    }

    public function getDepth(): DepthService
    {
        return $this->depthService ??= new DepthService();
    }

    public function getSuggestions(): SuggestionService
    {
        return $this->suggestionService ??= new SuggestionService();
    }

    public function getEmbedding(): EmbeddingService
    {
        return $this->embeddingService ??= new EmbeddingService();
    }

    public function getReports(): ReportService
    {
        return $this->reportService ??= new ReportService();
    }

    public function getExport(): ExportService
    {
        return $this->exportService ??= new ExportService();
    }

    public function getTrends(): TrendService
    {
        return $this->trendService ??= new TrendService();
    }

    /**
     * Returns the feature settings, memoized for the request. Layering:
     * `config/beacon.php` (`links` sub-array) > DB row > model defaults.
     */
    public function getSettings(): LinkSettings
    {
        if ($this->settingsModel !== null) {
            return $this->settingsModel;
        }

        $settings = new LinkSettings();
        $record = LinkSettingsRecord::findOne(1);
        if ($record !== null) {
            $settings->setAttributes([
                'enabledSections' => Json::decodeStringList(is_string($record->enabledSections) ? $record->enabledSections : null),
                'maxKeywordsPerEntry' => (int) $record->maxKeywordsPerEntry,
                'stopWordsLanguage' => (string) $record->stopWordsLanguage,
                'minKeywordLength' => (int) $record->minKeywordLength,
                'indexOnSave' => (bool) $record->indexOnSave,
                'showSidebarSuggestions' => (bool) $record->showSidebarSuggestions,
                'maxSuggestions' => (int) $record->maxSuggestions,
                'minScore' => (float) $record->minScore,
                'maxDocumentFrequencyRatio' => (float) $record->maxDocumentFrequencyRatio,
                'excludeSameSection' => (bool) $record->excludeSameSection,
                'embeddingsEnabled' => (bool) $record->embeddingsEnabled,
                'embeddingsBaseUrl' => (string) ($record->embeddingsBaseUrl ?? ''),
                'embeddingsApiKey' => (string) ($record->embeddingsApiKey ?? ''),
                'embeddingsModel' => (string) $record->embeddingsModel,
                'reportCacheDuration' => (int) $record->reportCacheDuration,
                'autoReindexInterval' => (int) $record->autoReindexInterval,
                'httpAuditTimeout' => (int) $record->httpAuditTimeout,
                'httpAuditDelay' => (int) $record->httpAuditDelay,
                'genericAnchorPatterns' => Json::decodeStringList(is_string($record->genericAnchorPatterns) ? $record->genericAnchorPatterns : null),
            ], false);
        }

        return $this->settingsModel = $this->applyConfigOverrides($settings);
    }

    /**
     * Persists the feature settings to the single-row table and clears the
     * memoized copy. Returns false (with validation errors on $settings) when
     * the model is invalid.
     */
    public function saveSettings(LinkSettings $settings): bool
    {
        if (!$settings->validate()) {
            return false;
        }

        $record = LinkSettingsRecord::findOne(1) ?? new LinkSettingsRecord(['id' => 1]);
        $record->setAttributes([
            'enabledSections' => Json::encode($settings->enabledSections),
            'maxKeywordsPerEntry' => $settings->maxKeywordsPerEntry,
            'stopWordsLanguage' => $settings->stopWordsLanguage,
            'minKeywordLength' => $settings->minKeywordLength,
            'indexOnSave' => $settings->indexOnSave,
            'showSidebarSuggestions' => $settings->showSidebarSuggestions,
            'maxSuggestions' => $settings->maxSuggestions,
            'minScore' => $settings->minScore,
            'maxDocumentFrequencyRatio' => $settings->maxDocumentFrequencyRatio,
            'excludeSameSection' => $settings->excludeSameSection,
            'embeddingsEnabled' => $settings->embeddingsEnabled,
            'embeddingsBaseUrl' => $settings->embeddingsBaseUrl,
            'embeddingsApiKey' => $settings->embeddingsApiKey,
            'embeddingsModel' => $settings->embeddingsModel,
            'reportCacheDuration' => $settings->reportCacheDuration,
            'autoReindexInterval' => $settings->autoReindexInterval,
            'httpAuditTimeout' => $settings->httpAuditTimeout,
            'httpAuditDelay' => $settings->httpAuditDelay,
            'genericAnchorPatterns' => Json::encode($settings->genericAnchorPatterns),
        ], false);
        $record->save(false);

        $this->settingsModel = null;
        return true;
    }

    /**
     * Overlays `config/beacon.php` `links` keys (secrets/power-user knobs) on
     * top of the DB-backed settings.
     */
    private function applyConfigOverrides(LinkSettings $settings): LinkSettings
    {
        $config = Craft::$app->getConfig()->getConfigFromFile('beacon');
        $overrides = (is_array($config) && isset($config['links']) && is_array($config['links'])) ? $config['links'] : [];
        foreach ($overrides as $key => $value) {
            if ($settings->canSetProperty($key)) {
                $settings->$key = $value;
            }
        }
        return $settings;
    }
}
