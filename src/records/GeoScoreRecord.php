<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $elementId
 * @property int $siteId
 * @property int $score
 * @property string $pillars JSON: map<pillarHandle, GeoPillarScoreArray>
 * @property string $sourceHash sha1 of inputs that produced the score
 * @property string $computedAt
 */
class GeoScoreRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_geo_score}}';
    }
}
