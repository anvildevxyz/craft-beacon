<?php

namespace anvildev\beacon\events;

use anvildev\beacon\models\SchemaGraphHolder;
use craft\elements\Entry;
use craft\web\Request;
use yii\base\Event;

/**
 * Fires once resolved JSON-LD graph rows are assembled and before markup is streamed.
 *
 * Modify `$holder->nodes` — add/remove/replace associative JSON-LD objects (each `@type`).
 *
 * **Idempotency contract:** This event may fire more than once per request when
 * `beacon.schemas()` is called multiple times in a template. Listeners must be
 * idempotent: replace nodes by `@id`/`@type` rather than blindly appending, or
 * track their own per-request guard. The cached `$holder->nodes` already
 * reflects prior listener output from the same request.
 *
 * @since 1.0.0
 */
class DefineSchemasEvent extends Event
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public SchemaGraphHolder $holder,
        public ?Entry $entry,
        public Request $request,
        array $config = [],
    ) {
        parent::__construct($config);
    }
}
