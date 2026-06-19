<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $siteId
 * @property int|null $promptId
 * @property string $promptText
 * @property string $engine
 * @property bool $cited
 * @property bool $domainMentioned
 * @property string|null $matchedUrls
 * @property string|null $competitorMentions
 * @property string|null $answerExcerpt
 * @property string $runAt
 */
class AiVisibilityResultRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_ai_visibility_results}}';
    }
}
