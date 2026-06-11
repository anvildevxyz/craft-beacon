<?php

namespace anvildev\beacon\tests\unit\records;

use anvildev\beacon\records\AdsSettingsRecord;
use anvildev\beacon\records\HumansSettingsRecord;
use PHPUnit\Framework\TestCase;

class PassBRecordsTest extends TestCase
{
    public function testTableNames(): void
    {
        $this->assertSame('{{%beacon_humans_settings}}', HumansSettingsRecord::tableName());
        $this->assertSame('{{%beacon_ads_settings}}', AdsSettingsRecord::tableName());
    }
}
