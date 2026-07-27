<?php

namespace anvildev\beacon\events;

use yii\base\Event;

class LinkSnapshotRecordedEvent extends Event
{
    public int $siteId;
    public string $snapshotDate;
    public int $totalLinks = 0;
    public int $brokenLinks = 0;
}
