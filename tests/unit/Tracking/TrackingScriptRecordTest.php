<?php

namespace anvildev\beacon\tests\unit\Tracking;

use anvildev\beacon\records\TrackingScriptRecord;
use PHPUnit\Framework\TestCase;

final class TrackingScriptRecordTest extends TestCase
{
    public function testTableNameMatchesMigration(): void
    {
        $this->assertSame('{{%beacon_tracking_scripts}}', TrackingScriptRecord::tableName());
    }
}
