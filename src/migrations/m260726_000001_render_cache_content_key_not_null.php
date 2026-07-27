<?php

namespace anvildev\beacon\migrations;

use Craft;
use craft\db\Migration;

/**
 * Makes `beacon_render_cache.contentKey` NOT NULL (empty string for the
 * "primary" document of a cache type).
 *
 * The table carries a unique index on `(siteId, type, contentKey)`, but both
 * MySQL and Postgres treat NULLs as distinct in a unique index — so the rows
 * that matter most (`sitemap` and `llmstxt` master documents, which use a NULL
 * `contentKey`) were never actually covered by it. Two concurrent writers could
 * insert duplicate rows for the same logical key, after which reads would pick
 * one arbitrarily. That window is real: `SitemapController::mutexedRebuild()`
 * deliberately rebuilds *without* the lock when the mutex backend is
 * unresponsive, rather than failing the request.
 *
 * With the column NOT NULL the index covers every row, which also lets
 * `RenderCacheService::set()` become a single upsert instead of a
 * read-then-write.
 *
 * The table is a pure cache, so this clears it rather than converting values in
 * place — any row is cheaper to rebuild on next request than to migrate, and
 * starting empty guarantees no pre-existing duplicate pair survives to violate
 * the tightened index.
 */
class m260726_000001_render_cache_content_key_not_null extends Migration
{
    private const TABLE = '{{%beacon_render_cache}}';

    public function safeUp(): bool
    {
        if (!$this->tableExists()) {
            return true;
        }

        $this->truncateTable(self::TABLE);
        $this->alterColumn(self::TABLE, 'contentKey', $this->string(64)->notNull()->defaultValue(''));

        return true;
    }

    public function safeDown(): bool
    {
        if (!$this->tableExists()) {
            return true;
        }

        $this->truncateTable(self::TABLE);
        $this->alterColumn(self::TABLE, 'contentKey', $this->string(64));

        return true;
    }

    private function tableExists(): bool
    {
        return Craft::$app->getDb()->getTableSchema(self::TABLE) !== null;
    }
}
