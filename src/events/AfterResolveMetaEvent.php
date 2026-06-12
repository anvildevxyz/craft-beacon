<?php

namespace anvildev\beacon\events;

use anvildev\beacon\models\SeoMeta;
use craft\elements\Entry;
use craft\web\Request;
use yii\base\Event;

/**
 * Read-only inspection point after Beacon finalizes meta (`DefineMetaEvent` included).
 *
 * @since 1.0.0
 */
class AfterResolveMetaEvent extends Event
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public SeoMeta $meta,
        public ?Entry $entry,
        public Request $request,
        array $config = [],
    ) {
        parent::__construct($config);
    }
}
