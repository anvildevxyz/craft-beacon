<?php

namespace anvildev\beacon\events;

use anvildev\beacon\records\LinkRecord;
use yii\base\Event;

class LinkBrokenCheckEvent extends Event
{
    public LinkRecord $link;
    public ?int $httpStatus = null;
    public bool $isBroken = false;
}
