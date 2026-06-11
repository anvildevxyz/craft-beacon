<?php

namespace anvildev\beacon\tests\unit\records;

use anvildev\beacon\records\AiBotRecord;
use anvildev\beacon\records\AiCrawlerRuleRecord;
use anvildev\beacon\records\LlmsSettingsRecord;
use anvildev\beacon\records\RobotsSettingsRecord;
use anvildev\beacon\records\SitemapSettingsRecord;
use PHPUnit\Framework\TestCase;

class Phase5bRecordsTest extends TestCase
{
    public function testTableNames(): void
    {
        $this->assertSame('{{%beacon_sitemap_settings}}', SitemapSettingsRecord::tableName());
        $this->assertSame('{{%beacon_llms_settings}}', LlmsSettingsRecord::tableName());
        $this->assertSame('{{%beacon_robots_settings}}', RobotsSettingsRecord::tableName());
        $this->assertSame('{{%beacon_ai_crawler_rules}}', AiCrawlerRuleRecord::tableName());
        $this->assertSame('{{%beacon_ai_bots}}', AiBotRecord::tableName());
    }
}
