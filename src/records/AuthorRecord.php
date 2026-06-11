<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * JSON columns (`expertise`, `credentials`, `sameAs`, `alumniOf`, `affiliation`,
 * `worksFor`) round-trip as `list<string>|null` — written by
 * {@see \anvildev\beacon\elements\AuthorElement::afterSave()} from properties
 * typed `?array` with `list<string>|null` PHPDoc, and validated as
 * `each => string` via the element's `defineRules()`.
 *
 * @property int $id
 * @property list<string>|null $expertise
 * @property list<string>|null $credentials
 * @property list<string>|null $sameAs
 * @property string|null $jobTitle
 * @property int|null $imageAssetId
 * @property string|null $description
 * @property list<string>|null $alumniOf
 * @property list<string>|null $affiliation
 * @property list<string>|null $worksFor
 */
class AuthorRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_authors}}';
    }
}
