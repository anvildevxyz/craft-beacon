<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * Single-row store (id = 1) for the Links feature settings.
 *
 * @property int $id
 * @property string|null $enabledSections JSON list of section handles
 * @property int $maxKeywordsPerEntry
 * @property string $stopWordsLanguage
 * @property int $minKeywordLength
 * @property bool $indexOnSave
 * @property bool $showSidebarSuggestions
 * @property int $maxSuggestions
 * @property float $minScore
 * @property float $maxDocumentFrequencyRatio
 * @property bool $excludeSameSection
 * @property bool $embeddingsEnabled
 * @property string|null $embeddingsBaseUrl
 * @property string|null $embeddingsApiKey
 * @property string $embeddingsModel
 * @property int $reportCacheDuration
 * @property int $autoReindexInterval
 * @property int $httpAuditTimeout
 * @property int $httpAuditDelay
 * @property string|null $genericAnchorPatterns JSON list of anchor phrases
 */
class LinkSettingsRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_link_settings}}';
    }
}
