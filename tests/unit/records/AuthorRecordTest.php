<?php

namespace anvildev\beacon\tests\unit\records;

use anvildev\beacon\records\AuthorRecord;
use PHPUnit\Framework\TestCase;

class AuthorRecordTest extends TestCase
{
    public function testTableNameIsBeaconAuthors(): void
    {
        $this->assertSame('{{%beacon_authors}}', AuthorRecord::tableName());
    }
}
