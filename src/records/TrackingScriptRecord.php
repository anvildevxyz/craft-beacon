<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

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
    use InvalidatesCacheTagTrait;

    public const CACHE_TAG = 'beacon_tracking_scripts';

    public static function tableName(): string
    {
        return '{{%beacon_tracking_scripts}}';
    }
}
