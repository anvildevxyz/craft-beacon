<?php

namespace anvildev\beacon\events;

use yii\base\Event;

class LinkIndexEntryEvent extends Event
{
    public int $elementId;
    public int $siteId;
    /** @var array<string, float> */
    public array $keywords = [];
}
