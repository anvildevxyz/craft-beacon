<?php

namespace anvildev\beacon\events;

use anvildev\beacon\models\SeoMeta;
use craft\elements\Entry;
use craft\web\Request;
use yii\base\Event;

/**
 * Mutable tag-container event fired immediately before Beacon renders `<meta>`
 * tags in {@see \anvildev\beacon\variables\BeaconVariable::head()}.
 *
 * @phpstan-import-type MetaTag from \anvildev\beacon\models\SeoMeta
 *
 * @since 1.0.0
 */
class DefineMetaTagsEvent extends Event
{
    /**
     * @param array<string,MetaTag> $tags
     * @param array<string, mixed> $config
     */
    public function __construct(
        public array &$tags,
        public SeoMeta $meta,
        public ?Entry $entry,
        public Request $request,
        array $config = [],
    ) {
        parent::__construct($config);
    }
}
