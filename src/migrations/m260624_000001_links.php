<?php

namespace anvildev\beacon\migrations;

use craft\db\Migration;

/**
 * Creates the tables for the Links (internal-link-graph) feature: the keyword
 * index, embedding vectors, the link graph, daily trend snapshots, computed
 * suggestions, and the single-row feature settings.
 */
class m260624_000001_links extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%beacon_link_index}}', [
            'id' => $this->primaryKey(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'keywords' => $this->text()->notNull(),
            'keywordHash' => $this->string(64)->notNull(),
            'dateIndexed' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%beacon_link_embeddings}}', [
            'id' => $this->primaryKey(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'embedding' => $this->binary()->notNull(),
            'model' => $this->string(255)->notNull(),
            'dateIndexed' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%beacon_links}}', [
            'id' => $this->primaryKey(),
            'sourceElementId' => $this->integer()->notNull(),
            'sourceSiteId' => $this->integer()->notNull(),
            'targetElementId' => $this->integer()->null(),
            'targetSiteId' => $this->integer()->null(),
            'targetElementType' => $this->string(255)->null(),
            'fieldHandle' => $this->string(255)->notNull(),
            'anchorText' => $this->string(500)->null(),
            'isExternal' => $this->boolean()->notNull()->defaultValue(false),
            'targetUrl' => $this->string(2000)->null(),
            'httpStatus' => $this->smallInteger()->null(),
            'httpCheckedAt' => $this->dateTime()->null(),
            'ignored' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%beacon_link_snapshots}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'snapshotDate' => $this->date()->notNull(),
            'orphanCount' => $this->integer()->notNull()->defaultValue(0),
            'avgLinksPerPage' => $this->float()->notNull()->defaultValue(0),
            'totalInternalLinks' => $this->integer()->notNull()->defaultValue(0),
            'brokenLinkCount' => $this->integer()->notNull()->defaultValue(0),
            'indexedEntryCount' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%beacon_link_suggestions}}', [
            'id' => $this->primaryKey(),
            'sourceElementId' => $this->integer()->notNull(),
            'targetElementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'status' => $this->string(20)->notNull()->defaultValue('suggested'),
            'score' => $this->float()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%beacon_link_settings}}', [
            'id' => $this->primaryKey(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'enabledSections' => $this->text()->null(),
            'maxKeywordsPerEntry' => $this->integer()->notNull()->defaultValue(50),
            'stopWordsLanguage' => $this->string(8)->notNull()->defaultValue('en'),
            'minKeywordLength' => $this->integer()->notNull()->defaultValue(3),
            'indexOnSave' => $this->boolean()->notNull()->defaultValue(true),
            'showSidebarSuggestions' => $this->boolean()->notNull()->defaultValue(true),
            'maxSuggestions' => $this->integer()->notNull()->defaultValue(10),
            'minScore' => $this->float()->notNull()->defaultValue(0.1),
            'maxDocumentFrequencyRatio' => $this->float()->notNull()->defaultValue(0.6),
            'excludeSameSection' => $this->boolean()->notNull()->defaultValue(false),
            'embeddingsEnabled' => $this->boolean()->notNull()->defaultValue(false),
            'embeddingsBaseUrl' => $this->string(500)->null(),
            'embeddingsApiKey' => $this->string(500)->null(),
            'embeddingsModel' => $this->string(255)->notNull()->defaultValue('text-embedding-3-small'),
            'reportCacheDuration' => $this->integer()->notNull()->defaultValue(3600),
            'autoReindexInterval' => $this->integer()->notNull()->defaultValue(0),
            'httpAuditTimeout' => $this->integer()->notNull()->defaultValue(10),
            'httpAuditDelay' => $this->integer()->notNull()->defaultValue(200),
            'genericAnchorPatterns' => $this->text()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%beacon_link_index}}', ['elementId', 'siteId'], true);
        $this->createIndex(null, '{{%beacon_link_index}}', ['keywordHash']);
        $this->createIndex(null, '{{%beacon_link_embeddings}}', ['elementId', 'siteId'], true);
        $this->createIndex(null, '{{%beacon_links}}', ['sourceElementId', 'sourceSiteId']);
        $this->createIndex(null, '{{%beacon_links}}', ['targetElementId', 'targetSiteId']);
        $this->createIndex(null, '{{%beacon_links}}', ['targetElementType']);
        $this->createIndex(null, '{{%beacon_links}}', ['isExternal']);
        $this->createIndex(null, '{{%beacon_links}}', ['httpStatus']);
        $this->createIndex(null, '{{%beacon_link_suggestions}}', ['sourceElementId', 'siteId']);
        $this->createIndex(null, '{{%beacon_link_suggestions}}', ['targetElementId']);
        $this->createIndex(null, '{{%beacon_link_suggestions}}', ['status']);
        $this->createIndex(null, '{{%beacon_link_suggestions}}', ['sourceElementId', 'targetElementId', 'siteId'], true);
        $this->createIndex(null, '{{%beacon_link_snapshots}}', ['siteId', 'snapshotDate'], true);

        $this->addForeignKey(null, '{{%beacon_link_index}}', ['elementId'], '{{%elements}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_link_index}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_link_embeddings}}', ['elementId'], '{{%elements}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_link_embeddings}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_links}}', ['sourceElementId'], '{{%elements}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_links}}', ['sourceSiteId'], '{{%sites}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_links}}', ['targetElementId'], '{{%elements}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_links}}', ['targetSiteId'], '{{%sites}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_link_suggestions}}', ['sourceElementId'], '{{%elements}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_link_suggestions}}', ['targetElementId'], '{{%elements}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_link_suggestions}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_link_snapshots}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%beacon_link_settings}}');
        $this->dropTableIfExists('{{%beacon_link_suggestions}}');
        $this->dropTableIfExists('{{%beacon_link_snapshots}}');
        $this->dropTableIfExists('{{%beacon_links}}');
        $this->dropTableIfExists('{{%beacon_link_embeddings}}');
        $this->dropTableIfExists('{{%beacon_link_index}}');

        return true;
    }
}
