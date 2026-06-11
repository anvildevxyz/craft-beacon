<?php

namespace anvildev\beacon\tests\unit\records;

use anvildev\beacon\records\RedirectRecord;
use PHPUnit\Framework\TestCase;

class RedirectRecordTest extends TestCase
{
    public function testTableName(): void
    {
        $this->assertSame('{{%beacon_redirects}}', RedirectRecord::tableName());
    }
}
