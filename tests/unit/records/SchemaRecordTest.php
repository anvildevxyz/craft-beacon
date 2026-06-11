<?php

namespace anvildev\beacon\tests\unit\records;

use anvildev\beacon\records\SchemaRecord;
use anvildev\beacon\records\SettingsRecord;
use PHPUnit\Framework\TestCase;

class SchemaRecordTest extends TestCase
{
    public function testSchemaTableName(): void
    {
        $this->assertSame('{{%beacon_schemas}}', SchemaRecord::tableName());
    }

    public function testSettingsTableName(): void
    {
        $this->assertSame('{{%beacon_settings}}', SettingsRecord::tableName());
    }
}
