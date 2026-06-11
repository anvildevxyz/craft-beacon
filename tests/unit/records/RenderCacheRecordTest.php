<?php

namespace anvildev\beacon\tests\unit\records;

use anvildev\beacon\records\RenderCacheRecord;
use PHPUnit\Framework\TestCase;

class RenderCacheRecordTest extends TestCase
{
    public function testTableName(): void
    {
        $this->assertSame('{{%beacon_render_cache}}', RenderCacheRecord::tableName());
    }
}
