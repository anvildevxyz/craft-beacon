<?php

namespace anvildev\beacon\records;

use Craft;
use craft\db\ActiveRecord;
use yii\caching\TagDependency;

/**
 * @phpstan-import-type SiteOverrides from \anvildev\beacon\services\SiteOverrideResolver
 *
 * @property int $id
 * @property string $name
 * @property string $provider
 * @property array<string, mixed>|string|null $config
 * @property string $placement
 * @property int $sortOrder
 * @property SiteOverrides|string|null $siteOverrides
 * @property \DateTime|string|null $dateCreated
 * @property \DateTime|string|null $dateUpdated
 * @property string $uid
 */
class TrackingScriptRecord extends ActiveRecord
{
    public const CACHE_TAG = 'beacon_tracking_scripts';

    public static function tableName(): string
    {
        return '{{%beacon_tracking_scripts}}';
    }

    /** @param array<string, mixed> $changedAttributes */
    public function afterSave($insert, $changedAttributes): void
    {
        parent::afterSave($insert, $changedAttributes);
        $this->invalidateCache();
    }

    public function afterDelete(): void
    {
        parent::afterDelete();
        $this->invalidateCache();
    }

    private function invalidateCache(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), self::CACHE_TAG);
    }
}
