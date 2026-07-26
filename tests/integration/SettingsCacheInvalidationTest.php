<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\Plugin;
use anvildev\beacon\records\AiBotRecord;
use anvildev\beacon\records\SchemaRecord;
use anvildev\beacon\records\SettingsRecord;
use Craft;
use craft\test\TestCase;

/**
 * The settings and registry rows Beacon reads on every front-end request are
 * cached across requests, so each one needs to prove that a write is visible
 * to the next read. A stale cache here would silently serve outdated SEO
 * output, which is exactly the class of bug that is miserable to trace back.
 */
final class SettingsCacheInvalidationTest extends TestCase
{
    protected function _before(): void
    {
        parent::_before();

        // The cross-request cache is not transactional, so rows written by an
        // earlier test and then rolled back can leave cached values behind.
        // Start from an empty cache so this test only sees its own writes.
        Craft::$app->getCache()->flush();

        // Services memoise within the request; the cross-request cache is what
        // is under test, so start from a service that has not read yet.
        $this->resetServices();
    }

    protected function _after(): void
    {
        Craft::$app->getCache()->flush();
        parent::_after();
    }

    public function testSavingSettingsIsVisibleToTheNextRead(): void
    {
        $record = SettingsRecord::findOne(1) ?? new SettingsRecord(['id' => 1]);
        $original = $record->titleTemplate;

        $record->titleTemplate = 'First {title}';
        $record->save(false);

        $this->resetServices();
        $this->assertSame('First {title}', Plugin::getInstance()->settings->get()->titleTemplate);

        $record->titleTemplate = 'Second {title}';
        $record->save(false);

        $this->resetServices();
        $this->assertSame('Second {title}', Plugin::getInstance()->settings->get()->titleTemplate);

        $record->titleTemplate = $original;
        $record->save(false);
    }

    public function testSavingBotIsVisibleToTheNextRead(): void
    {
        $bot = new AiBotRecord();
        $bot->name = 'CacheProbeBot';
        $bot->userAgentPattern = 'CacheProbeBot/.*';
        $bot->enabled = true;
        $bot->sortOrder = 9999;
        $bot->source = 'custom';
        $bot->save(false);

        $this->resetServices();
        $names = array_map(fn($b) => $b->name, Plugin::getInstance()->aiBots->getEnabledBots());
        $this->assertContains('CacheProbeBot', $names);

        // Disabling must drop it from the cached enabled-only list.
        $bot->enabled = false;
        $bot->save(false);

        $this->resetServices();
        $names = array_map(fn($b) => $b->name, Plugin::getInstance()->aiBots->getEnabledBots());
        $this->assertNotContains('CacheProbeBot', $names);

        $bot->delete();
    }

    public function testReorderingSchemasIsVisibleToTheNextRead(): void
    {
        $first = $this->makeSchema('cacheProbeType', 'Article', 0);
        $second = $this->makeSchema('cacheProbeType', 'FAQPage', 1);

        $this->resetServices();
        $types = $this->schemaTypesFor('cacheProbeType');
        $this->assertSame(['Article', 'FAQPage'], $types);

        // applyOrder() uses updateAll(), which fires no record lifecycle hook —
        // the service has to invalidate the tag itself.
        Plugin::getInstance()->schema->applyOrder([$second->id, $first->id]);

        $this->resetServices();
        $this->assertSame(['FAQPage', 'Article'], $this->schemaTypesFor('cacheProbeType'));

        $first->delete();
        $second->delete();
    }

    private function makeSchema(string $entryTypeHandle, string $schemaType, int $sortOrder): SchemaRecord
    {
        $record = new SchemaRecord();
        $record->entryTypeHandle = $entryTypeHandle;
        $record->schemaType = $schemaType;
        $record->mapping = '{}';
        $record->sortOrder = $sortOrder;
        $record->enabled = true;
        $record->save(false);

        return $record;
    }

    /**
     * @return list<string>
     */
    private function schemaTypesFor(string $entryTypeHandle): array
    {
        return array_map(
            fn($s) => $s->schemaType,
            Plugin::getInstance()->bundles->getSchemasForEntryType($entryTypeHandle),
        );
    }

    /**
     * Rebuilds the services so only the cross-request cache can satisfy a read.
     */
    private function resetServices(): void
    {
        $plugin = Plugin::getInstance();
        $plugin->set('schema', \anvildev\beacon\services\SchemaService::class);
        $plugin->set('settings', \anvildev\beacon\services\SettingsService::class);
        $plugin->set('aiBots', \anvildev\beacon\services\AiBotsService::class);
        $plugin->set('bundles', \anvildev\beacon\services\BundleRegistry::class);
        $plugin->set('siteSettings', \anvildev\beacon\services\SiteSettingsService::class);
    }
}
