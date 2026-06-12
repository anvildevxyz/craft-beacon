<?php

namespace anvildev\beacon\events;

use anvildev\beacon\models\SeoMeta;
use craft\elements\Entry;
use craft\web\Request;
use yii\base\Event;

/**
 * Fires after Beacon merges field-derived SEO with variable overrides and pagination,
 * immediately before Twig renders {@see \anvildev\beacon\variables\BeaconVariable::head()}.
 *
 * Mutate `$meta` public properties in place (`$meta` is the same instance passed to Twig).
 *
 * @since 1.0.0
 */
class DefineMetaEvent extends Event
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
