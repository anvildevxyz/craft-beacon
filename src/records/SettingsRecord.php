<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $titleTemplate
 * @property string|null $descriptionTemplate
 * @property string|null $organizationName
 * @property int|null $organizationLogoAssetId
 * @property int|null $organizationImageAssetId
 * @property string|null $socialProfiles
 * @property string $identityType
 * @property string|null $identityAdvanced
 * @property int $staleThresholdDays
 * @property int $botLogRetentionDays
 * @property bool $hreflangEnabled
 * @property string|null $hreflangXDefaultSiteHandle
 * @property bool $geoMarkdownEnabled
 * @property string $geoMarkdownBodyFieldHandle
 * @property bool $geoMarkdownNegotiateAcceptHeader
 * @property bool $geoMarkdownMdSuffixEnabled
 * @property bool $geoMarkdownExcerptFallbackToDescription
 * @property bool $geoMarkdownAutoServeBots
 * @property bool $geoProvenanceSchemaEnabled
 * @property int|null $defaultSocialImageId
 * @property string|null $sectionSeoDefaults
 * @property int|null $metaCacheDuration
 * @property string|null $robotsDirectivesEnabled
 * @property bool $indexNowEnabled
 * @property bool $authorPagesEnabled
 * @property string $authorPagesUriPrefix
 * @property bool $geoScoreEnabled
 * @property string|null $geoScorePillarWeights
 * @property string $geoScoreClaimDetectionMode
 * @property string $geoScoreFactDetectionMode
 * @property bool $aiEnabled
 * @property string $aiProvider
 * @property string $aiModel
 * @property string|null $aiApiKey
 * @property string|null $aiBaseUrl
 * @property bool $aiVisibilityEnabled
 * @property string|null $aiVisibilityEngines
 * @property string|null $aiVisibilityCompetitorDomains
 * @property int $aiVisibilityMaxPerRun
 * @property int $aiVisibilityResultRetentionDays
 * @property string $aiVisibilityCadence
 */
class SettingsRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_settings}}';
    }
}
