<?php

namespace anvildev\beacon\migrations;

use Craft;
use craft\db\Migration;
use craft\helpers\StringHelper;
use yii\db\Query;

/**
 * Install migration for Beacon. Creates the full schema in one pass, then
 * seeds default data:
 *
 *   - one {{%beacon_settings}} row with id=1
 *   - the canonical AI bot list
 *   - per-site default rows for sitemap / llms / robots / humans / ads /
 *     breadcrumb / webmaster, for every existing site
 */
class Install extends Migration
{
    public function safeUp(): void
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();
        $this->fixUtf8Mb4Columns();
        $this->seedDefaults();
    }

    public function safeDown(): void
    {
        $this->dropTableIfExists('{{%beacon_short_links}}');
        $this->dropTableIfExists('{{%beacon_redirect_404_log}}');
        $this->dropTableIfExists('{{%beacon_ai_visibility_results}}');
        $this->dropTableIfExists('{{%beacon_benchmark_prompts}}');
        $this->dropTableIfExists('{{%beacon_tracking_scripts}}');
        $this->dropTableIfExists('{{%beacon_webmaster_settings}}');
        $this->dropTableIfExists('{{%beacon_ads_settings}}');
        $this->dropTableIfExists('{{%beacon_humans_settings}}');
        $this->dropTableIfExists('{{%beacon_robots_settings}}');
        $this->dropTableIfExists('{{%beacon_llms_settings}}');
        $this->dropTableIfExists('{{%beacon_sitemap_settings}}');
        $this->dropTableIfExists('{{%beacon_ai_crawler_rules}}');
        $this->dropTableIfExists('{{%beacon_ai_bots}}');
        $this->dropTableIfExists('{{%beacon_bot_log}}');
        $this->dropTableIfExists('{{%beacon_indexnow_submissions}}');
        $this->dropTableIfExists('{{%beacon_geo_markdown}}');
        $this->dropTableIfExists('{{%beacon_geo_score}}');
        $this->dropTableIfExists('{{%beacon_render_cache}}');
        $this->dropTableIfExists('{{%beacon_settings}}');
        $this->dropTableIfExists('{{%beacon_schemas}}');
        $this->dropTableIfExists('{{%beacon_redirects}}');
        $this->dropTableIfExists('{{%beacon_author_relations}}');
        $this->dropTableIfExists('{{%beacon_authors}}');
    }

    private function createTables(): void
    {
        $this->createTable('{{%beacon_authors}}', [
            'id' => $this->integer()->notNull(),
            'expertise' => $this->json(),
            'credentials' => $this->json(),
            'sameAs' => $this->json(),
            'jobTitle' => $this->string(255),
            'imageAssetId' => $this->integer(),
            'description' => $this->text(),
            'alumniOf' => $this->json(),
            'affiliation' => $this->json(),
            'worksFor' => $this->json(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createTable('{{%beacon_author_relations}}', [
            'id' => $this->primaryKey(),
            'authorId' => $this->integer()->notNull(),
            'elementId' => $this->integer()->notNull(),
            'role' => $this->string(64),
            'sortOrder' => $this->smallInteger()->unsigned(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%beacon_redirects}}', [
            'id' => $this->integer()->notNull(),
            'propagationMethod' => $this->string(20)->notNull()->defaultValue('none'),
            'sourceUri' => $this->string(255)->notNull(),
            'targetUri' => $this->string(500)->notNull(),
            'statusCode' => $this->smallInteger()->unsigned()->notNull()->defaultValue(301),
            'type' => $this->string(8)->notNull()->defaultValue('exact'),
            'queryStringMode' => $this->string(16)->notNull()->defaultValue('ignore'),
            'hits' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'lastHit' => $this->dateTime(),
            'note' => $this->string(500),
            'source' => $this->string(16)->notNull()->defaultValue('manual'),
            'sortOrder' => $this->integer()->notNull()->defaultValue(0),
            'elementId' => $this->integer(),
            'elementSiteId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createTable('{{%beacon_short_links}}', [
            'id' => $this->integer()->notNull(),
            'propagationMethod' => $this->string(20)->notNull()->defaultValue('all'),
            'slug' => $this->string(128)->notNull(),
            'destination' => $this->string(1000)->notNull(),
            'statusCode' => $this->smallInteger()->unsigned()->notNull()->defaultValue(302),
            'clicks' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'lastClicked' => $this->dateTime(),
            'expiresAt' => $this->dateTime(),
            'note' => $this->string(500),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createTable('{{%beacon_redirect_404_log}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'uri' => $this->string(500)->notNull(),
            'hits' => $this->integer()->unsigned()->notNull()->defaultValue(1),
            'firstSeen' => $this->dateTime()->notNull(),
            'lastSeen' => $this->dateTime()->notNull(),
            'referer' => $this->string(500),
            'handled' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%beacon_schemas}}', [
            'id' => $this->primaryKey(),
            'entryTypeHandle' => $this->string(64)->notNull(),
            'schemaType' => $this->string(64)->notNull(),
            'mapping' => $this->longText()->notNull(),
            'sortOrder' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%beacon_settings}}', [
            'id' => $this->integer()->notNull()->defaultValue(1),
            'titleTemplate' => $this->string(500)->notNull()->defaultValue('{title}'),
            'descriptionTemplate' => $this->string(500),
            'organizationName' => $this->string(255),
            'organizationLogoAssetId' => $this->integer(),
            'organizationImageAssetId' => $this->integer(),
            'redirectStructureId' => $this->integer(),
            'socialProfiles' => $this->longText(),
            'identityType' => $this->string(32)->notNull()->defaultValue('Organization'),
            'identityAdvanced' => $this->longText(),
            'sectionSeoDefaults' => $this->longText(),
            'staleThresholdDays' => $this->smallInteger()->unsigned()->notNull()->defaultValue(90),
            'botLogRetentionDays' => $this->smallInteger()->unsigned()->notNull()->defaultValue(30),
            'metaCacheDuration' => $this->integer(),
            'defaultSocialImageId' => $this->integer(),
            'hreflangEnabled' => $this->boolean()->notNull()->defaultValue(false),
            'hreflangXDefaultSiteHandle' => $this->string(64),
            'geoMarkdownEnabled' => $this->boolean()->notNull()->defaultValue(true),
            'geoMarkdownBodyFieldHandle' => $this->string(64)->notNull()->defaultValue('body'),
            'geoMarkdownNegotiateAcceptHeader' => $this->boolean()->notNull()->defaultValue(true),
            'geoMarkdownMdSuffixEnabled' => $this->boolean()->notNull()->defaultValue(true),
            'geoMarkdownExcerptFallbackToDescription' => $this->boolean()->notNull()->defaultValue(true),
            'geoMarkdownAutoServeBots' => $this->boolean()->notNull()->defaultValue(true),
            'geoProvenanceSchemaEnabled' => $this->boolean()->notNull()->defaultValue(true),
            'robotsDirectivesEnabled' => $this->text(),
            'indexNowEnabled' => $this->boolean()->notNull()->defaultValue(false),
            'authorPagesEnabled' => $this->boolean()->notNull()->defaultValue(false),
            'authorPagesUriPrefix' => $this->string(64)->notNull()->defaultValue('authors'),
            'geoScoreEnabled' => $this->boolean()->notNull()->defaultValue(true),
            'geoScorePillarWeights' => $this->text(),
            'geoScoreClaimDetectionMode' => $this->string(16)->notNull()->defaultValue('heuristic'),
            'geoScoreFactDetectionMode' => $this->string(16)->notNull()->defaultValue('heuristic'),
            'aiEnabled' => $this->boolean()->notNull()->defaultValue(false),
            'aiProvider' => $this->string(32)->notNull()->defaultValue('anthropic'),
            'aiModel' => $this->string(128)->notNull()->defaultValue(''),
            'aiApiKey' => $this->string(512),
            'aiBaseUrl' => $this->string(255),
            'aiVisibilityEnabled' => $this->boolean()->notNull()->defaultValue(false),
            'aiVisibilityEngines' => $this->text(),
            'aiVisibilityCompetitorDomains' => $this->text(),
            'aiVisibilityMaxPerRun' => $this->smallInteger()->unsigned()->notNull()->defaultValue(50),
            'aiVisibilityResultRetentionDays' => $this->integer()->unsigned()->notNull()->defaultValue(365),
            'aiVisibilityCadence' => $this->string(16)->notNull()->defaultValue('off'),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createTable('{{%beacon_benchmark_prompts}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'prompt' => $this->text()->notNull(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%beacon_ai_visibility_results}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'promptId' => $this->integer(),
            'promptText' => $this->text()->notNull(),
            'engine' => $this->string(64)->notNull(),
            'cited' => $this->boolean()->notNull()->defaultValue(false),
            'domainMentioned' => $this->boolean()->notNull()->defaultValue(false),
            'matchedUrls' => $this->text(),
            'competitorMentions' => $this->text(),
            'answerExcerpt' => $this->text(),
            'runAt' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%beacon_geo_score}}', [
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'score' => $this->tinyInteger()->unsigned()->notNull(),
            'pillars' => $this->json()->notNull(),
            'sourceHash' => $this->char(64)->notNull(),
            'computedAt' => $this->dateTime()->notNull(),
            'PRIMARY KEY([[elementId]], [[siteId]])',
        ]);

        $this->createTable('{{%beacon_render_cache}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'type' => $this->string(16)->notNull(),
            'contentKey' => $this->string(64),
            'content' => $this->longText()->notNull(),
            'generatedAt' => $this->dateTime()->notNull(),
            'validUntil' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%beacon_geo_markdown}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'elementId' => $this->integer()->notNull(),
            'markdown' => $this->longText(),
            'hash' => $this->string(64),
            'dateGenerated' => $this->dateTime(),
            'dateRequested' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%beacon_bot_log}}', [
            'id' => $this->bigPrimaryKey(),
            'siteId' => $this->integer()->notNull(),
            'botName' => $this->string(64)->notNull(),
            'path' => $this->string(255)->notNull(),
            'hitAt' => $this->dateTime()->notNull(),
        ]);

        $this->createTable('{{%beacon_indexnow_submissions}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'urlCount' => $this->integer()->notNull(),
            'firstUrl' => $this->string(1024),
            'statusCode' => $this->smallInteger(),
            'succeeded' => $this->boolean()->notNull()->defaultValue(false),
            'note' => $this->string(255),
            'submittedAt' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%beacon_ai_bots}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(64)->notNull()->unique(),
            'userAgentPattern' => $this->string(255)->notNull(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'source' => $this->string(16)->notNull()->defaultValue('custom'),
            'sortOrder' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%beacon_ai_crawler_rules}}', [
            'id' => $this->primaryKey(),
            'botName' => $this->string(64)->notNull(),
            'allowPaths' => $this->longText()->notNull(),
            'disallowPaths' => $this->longText()->notNull(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'sortOrder' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%beacon_sitemap_settings}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull()->unique(),
            'sections' => $this->longText()->notNull(),
            'excludeSections' => $this->longText()->notNull(),
            'priority' => $this->decimal(2, 1)->notNull()->defaultValue(0.8),
            'changefreq' => $this->string(16)->notNull()->defaultValue('weekly'),
            'newsSections' => $this->json(),
            'sectionSitemap' => $this->longText(),
            'geoMarkdownFrontMatter' => $this->longText(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%beacon_llms_settings}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull()->unique(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'summary' => $this->text(),
            'siteNameOverride' => $this->string(255),
            'sections' => $this->longText()->notNull(),
            'policyUrl' => $this->string(500),
            'licenseUrl' => $this->string(500),
            'contactEmail' => $this->string(255),
            'preferredAttribution' => $this->text(),
            'fullBody' => $this->mediumText(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%beacon_robots_settings}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull()->unique(),
            'sitemapUrl' => $this->string(500)->notNull()->defaultValue('auto'),
            'userAgentRules' => $this->longText()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%beacon_humans_settings}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull()->unique(),
            'enabled' => $this->boolean()->notNull()->defaultValue(false),
            'body' => $this->longText(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%beacon_ads_settings}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull()->unique(),
            'enabled' => $this->boolean()->notNull()->defaultValue(false),
            'assetId' => $this->integer(),
            'body' => $this->longText(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%beacon_webmaster_settings}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull()->unique(),
            'indexNowKey' => $this->string(128),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%beacon_tracking_scripts}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            'provider' => $this->string(64)->notNull(),
            'config' => $this->json()->notNull(),
            'placement' => $this->string(16)->notNull()->defaultValue('head'),
            'sortOrder' => $this->smallInteger()->notNull()->defaultValue(0),
            'siteOverrides' => $this->json(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, '{{%beacon_author_relations}}', ['authorId', 'elementId'], true);
        $this->createIndex(null, '{{%beacon_author_relations}}', ['elementId']);

        $this->createIndex(null, '{{%beacon_redirects}}', ['sourceUri']);
        $this->createIndex(null, '{{%beacon_redirects}}', ['type']);
        $this->createIndex(null, '{{%beacon_redirects}}', ['sortOrder']);
        $this->createIndex(null, '{{%beacon_redirects}}', ['lastHit']);
        $this->createIndex(null, '{{%beacon_redirects}}', ['elementId', 'elementSiteId']);

        $this->createIndex(null, '{{%beacon_redirect_404_log}}', ['siteId', 'uri'], true);
        $this->createIndex(null, '{{%beacon_redirect_404_log}}', ['handled', 'hits']);
        $this->createIndex(null, '{{%beacon_redirect_404_log}}', ['lastSeen']);

        $this->createIndex(null, '{{%beacon_short_links}}', ['slug'], true);
        $this->createIndex(null, '{{%beacon_short_links}}', ['expiresAt']);

        $this->createIndex(null, '{{%beacon_schemas}}', ['entryTypeHandle', 'enabled', 'sortOrder']);

        $this->createIndex('idx_beacon_geo_score_band', '{{%beacon_geo_score}}', ['siteId', 'score']);

        $this->createIndex(null, '{{%beacon_render_cache}}', ['siteId', 'type', 'contentKey'], true);
        $this->createIndex(null, '{{%beacon_render_cache}}', ['siteId', 'type']);

        $this->createIndex(null, '{{%beacon_geo_markdown}}', ['siteId', 'elementId'], true);
        $this->createIndex(null, '{{%beacon_geo_markdown}}', ['elementId']);
        $this->createIndex(null, '{{%beacon_geo_markdown}}', ['dateGenerated']);

        $this->createIndex(null, '{{%beacon_bot_log}}', ['siteId', 'botName', 'hitAt']);
        $this->createIndex(null, '{{%beacon_bot_log}}', ['hitAt']);

        $this->createIndex(null, '{{%beacon_indexnow_submissions}}', ['siteId', 'submittedAt']);
        $this->createIndex(null, '{{%beacon_indexnow_submissions}}', ['submittedAt']);

        $this->createIndex(null, '{{%beacon_ai_bots}}', ['enabled', 'sortOrder']);
        $this->createIndex(null, '{{%beacon_ai_crawler_rules}}', ['enabled', 'sortOrder']);

        $this->createIndex(null, '{{%beacon_tracking_scripts}}', ['provider']);
        $this->createIndex(null, '{{%beacon_tracking_scripts}}', ['placement', 'sortOrder']);

        $this->createIndex(null, '{{%beacon_benchmark_prompts}}', ['siteId']);
        $this->createIndex(null, '{{%beacon_ai_visibility_results}}', ['siteId', 'runAt']);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, '{{%beacon_authors}}', ['id'], '{{%elements}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_author_relations}}', ['authorId'], '{{%elements}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_author_relations}}', ['elementId'], '{{%elements}}', ['id'], 'CASCADE');

        $this->addForeignKey(null, '{{%beacon_redirects}}', ['id'], '{{%elements}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_redirects}}', ['elementId'], '{{%elements}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_redirect_404_log}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_short_links}}', ['id'], '{{%elements}}', ['id'], 'CASCADE');

        $this->addForeignKey(null, '{{%beacon_settings}}', ['organizationLogoAssetId'], '{{%assets}}', ['id'], 'SET NULL');
        $this->addForeignKey(null, '{{%beacon_settings}}', ['defaultSocialImageId'], '{{%assets}}', ['id'], 'SET NULL');

        $this->addForeignKey('fk_beacon_geo_score_element', '{{%beacon_geo_score}}', ['elementId'], '{{%elements}}', ['id'], 'CASCADE');

        $this->addForeignKey(null, '{{%beacon_render_cache}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE');

        $this->addForeignKey(null, '{{%beacon_geo_markdown}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_geo_markdown}}', ['elementId'], '{{%elements}}', ['id'], 'CASCADE');

        $this->addForeignKey(null, '{{%beacon_bot_log}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_indexnow_submissions}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE');

        $this->addForeignKey(null, '{{%beacon_benchmark_prompts}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_ai_visibility_results}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_ai_visibility_results}}', ['promptId'], '{{%beacon_benchmark_prompts}}', ['id'], 'SET NULL');

        $this->addForeignKey(null, '{{%beacon_sitemap_settings}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_llms_settings}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_robots_settings}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_humans_settings}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_ads_settings}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%beacon_ads_settings}}', ['assetId'], '{{%assets}}', ['id'], 'SET NULL');
        $this->addForeignKey(null, '{{%beacon_webmaster_settings}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE');
    }

    private function fixUtf8Mb4Columns(): void
    {
        // MySQL-only: ensure the markdown column can store 4-byte emoji / CJK without
        // row-length overflow on utf8mb3 tables.
        if (!Craft::$app->getDb()->getIsMysql()) {
            return;
        }
        $this->execute(
            'ALTER TABLE {{%beacon_geo_markdown}} '
            . 'MODIFY `markdown` LONGTEXT '
            . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
        );
    }

    private function seedDefaults(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->insert('{{%beacon_settings}}', [
            'id' => 1,
            'titleTemplate' => '{title}',
            'socialProfiles' => '{}',
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ]);

        $this->seedDefaultBots($now);
        $this->seedPerSiteRows($now);
    }

    private function seedDefaultBots(string $now): void
    {
        $bots = [
            ['name' => 'GPTBot',            'userAgentPattern' => 'GPTBot/.*'],
            ['name' => 'OAI-SearchBot',     'userAgentPattern' => 'OAI-SearchBot/.*'],
            ['name' => 'ChatGPT-User',      'userAgentPattern' => 'ChatGPT-User/.*'],
            ['name' => 'ClaudeBot',         'userAgentPattern' => 'ClaudeBot/.*'],
            ['name' => 'Claude-Web',        'userAgentPattern' => 'Claude-Web/.*'],
            ['name' => 'PerplexityBot',     'userAgentPattern' => 'PerplexityBot/.*'],
            ['name' => 'Google-Extended',   'userAgentPattern' => 'Google-Extended'],
            ['name' => 'Bytespider',        'userAgentPattern' => 'Bytespider'],
            ['name' => 'Amazonbot',         'userAgentPattern' => 'Amazonbot/.*'],
            ['name' => 'Applebot-Extended', 'userAgentPattern' => 'Applebot-Extended'],
            ['name' => 'Diffbot',           'userAgentPattern' => 'Diffbot'],
            ['name' => 'cohere-ai',         'userAgentPattern' => 'cohere-ai'],
        ];

        foreach ($bots as $i => $bot) {
            $this->insert('{{%beacon_ai_bots}}', [
                ...$bot,
                'enabled' => true,
                'source' => 'default',
                'sortOrder' => $i,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ]);
        }
    }

    private function seedPerSiteRows(string $now): void
    {
        $siteIds = (new Query())
            ->select(['id'])
            ->from('{{%sites}}')
            ->where(['dateDeleted' => null])
            ->column();

        foreach ($siteIds as $siteId) {
            $siteId = (int) $siteId;
            $meta = ['dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID()];

            $this->insert('{{%beacon_sitemap_settings}}', [
                'siteId' => $siteId,
                'sections' => '[]',
                'excludeSections' => '[]',
                'priority' => '0.8',
                'changefreq' => 'weekly',
                'sectionSitemap' => '{}',
                'geoMarkdownFrontMatter' => '{}',
                ...$meta,
            ]);

            $this->insert('{{%beacon_llms_settings}}', [
                'siteId' => $siteId,
                'enabled' => true,
                'sections' => '[]',
                ...$meta,
            ]);

            $this->insert('{{%beacon_robots_settings}}', [
                'siteId' => $siteId,
                'sitemapUrl' => 'auto',
                'userAgentRules' => '[]',
                ...$meta,
            ]);

            $this->insert('{{%beacon_humans_settings}}', [
                'siteId' => $siteId,
                'enabled' => false,
                ...$meta,
            ]);

            $this->insert('{{%beacon_ads_settings}}', [
                'siteId' => $siteId,
                'enabled' => false,
                ...$meta,
            ]);

            $this->insert('{{%beacon_webmaster_settings}}', [
                'siteId' => $siteId,
                ...$meta,
            ]);
        }
    }
}
