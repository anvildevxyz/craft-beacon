<?php

namespace anvildev\beacon\events;

use yii\base\Event;

class LinkSuggestionsGeneratedEvent extends Event
{
    public int $sourceElementId;
    public int $siteId;
    /** @var list<array{targetElementId: int, score: float}> */
    public array $suggestions = [];
}
