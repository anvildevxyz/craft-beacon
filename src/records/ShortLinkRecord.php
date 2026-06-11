<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * Element-backed data row for a short link. `id` is a foreign key to
 * `{{%elements}}` — one row per {@see \anvildev\beacon\elements\ShortLinkElement},
 * holding the attributes that are shared across every site the element
 * propagates to. Site membership lives in `{{%elements_sites}}`; on/off state
 * is the element's native enabled status.
 *
 * @property int $id
 * @property string $propagationMethod
 * @property string $slug
 * @property string $destination
 * @property int $statusCode
 * @property int $clicks
 * @property string|null $lastClicked
 * @property string|null $expiresAt
 * @property string|null $note
 */
class ShortLinkRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_short_links}}';
    }
}
