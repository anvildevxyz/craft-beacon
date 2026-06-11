<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * Element-backed data row for a redirect. `id` is a foreign key to
 * `{{%elements}}` — one row per {@see \anvildev\beacon\elements\RedirectElement},
 * holding the attributes shared across the sites the rule propagates to. Site
 * membership lives in `{{%elements_sites}}`; on/off is the element's native
 * enabled status.
 *
 * `elementId` / `elementSiteId` are unrelated to the redirect's own id — they
 * point at the *source entry* an attached redirect belongs to (set by
 * {@see \anvildev\beacon\fields\BeaconRedirectSourcesField}).
 *
 * @property int $id
 * @property string $propagationMethod
 * @property string $sourceUri
 * @property string $targetUri
 * @property int $statusCode
 * @property string $type
 * @property string $queryStringMode
 * @property int $hits
 * @property string|null $lastHit
 * @property string|null $note
 * @property string $source
 * @property int $sortOrder
 * @property int|null $elementId
 * @property int|null $elementSiteId
 */
class RedirectRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_redirects}}';
    }
}
