<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $siteId
 * @property bool $enabled
 * @property string|null $summary
 * @property string|null $siteNameOverride
 * @property string $sections
 * @property string|null $policyUrl
 * @property string|null $licenseUrl
 * @property string|null $contactEmail
 * @property string|null $preferredAttribution
 * @property string|null $fullBody
 */
class LlmsSettingsRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_llms_settings}}';
    }
}
