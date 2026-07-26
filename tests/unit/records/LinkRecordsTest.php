<?php

namespace anvildev\beacon\tests\unit\records;

use anvildev\beacon\records\LinkEmbeddingRecord;
use anvildev\beacon\records\LinkIndexRecord;
use anvildev\beacon\records\LinkRecord;
use anvildev\beacon\records\LinkSettingsRecord;
use anvildev\beacon\records\LinkSnapshotRecord;
use anvildev\beacon\records\LinkSuggestionRecord;
use PHPUnit\Framework\TestCase;

class LinkRecordsTest extends TestCase
{
    public function testTableNames(): void
    {
        $this->assertSame('{{%beacon_links}}', LinkRecord::tableName());
        $this->assertSame('{{%beacon_link_index}}', LinkIndexRecord::tableName());
        $this->assertSame('{{%beacon_link_embeddings}}', LinkEmbeddingRecord::tableName());
        $this->assertSame('{{%beacon_link_snapshots}}', LinkSnapshotRecord::tableName());
        $this->assertSame('{{%beacon_link_suggestions}}', LinkSuggestionRecord::tableName());
        $this->assertSame('{{%beacon_link_settings}}', LinkSettingsRecord::tableName());
    }
}
