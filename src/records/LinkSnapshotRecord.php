<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $siteId
 * @property string $snapshotDate
 * @property int $orphanCount
 * @property float $avgLinksPerPage
 * @property int $totalInternalLinks
 * @property int $brokenLinkCount
 * @property int $indexedEntryCount
 */
class LinkSnapshotRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_link_snapshots}}';
    }
}
