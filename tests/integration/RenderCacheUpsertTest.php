<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\enums\RenderCacheType;
use anvildev\beacon\Plugin;
use anvildev\beacon\records\RenderCacheRecord;
use Craft;
use craft\test\TestCase;

final class RenderCacheUpsertTest extends TestCase
{
    private int $siteId;

    protected function _before(): void
    {
        parent::_before();
        $this->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $this->clear();
    }

    protected function _after(): void
    {
        $this->clear();
        parent::_after();
    }

    /**
     * The master document of a cache type uses a null `contentKey`, which a
     * unique index does not constrain — NULLs compare as distinct. Before
     * `contentKey` became NOT NULL, repeatedly writing that key inserted a new
     * row every time and reads then picked one arbitrarily.
     */
    public function testRepeatedWritesToTheMasterKeyReplaceRatherThanAccumulate(): void
    {
        $cache = Plugin::getInstance()->renderCache;

        $cache->set($this->siteId, RenderCacheType::Sitemap, null, 'first');
        $cache->set($this->siteId, RenderCacheType::Sitemap, null, 'second');
        $cache->set($this->siteId, RenderCacheType::Sitemap, null, 'third');

        $this->assertSame(1, $this->rowCount(), 'master-key writes must upsert, not accumulate rows');
        $this->assertSame('third', $cache->get($this->siteId, RenderCacheType::Sitemap, null)?->content);
    }

    public function testKeyedAndMasterEntriesCoexist(): void
    {
        $cache = Plugin::getInstance()->renderCache;

        $cache->set($this->siteId, RenderCacheType::Sitemap, null, 'master');
        $cache->set($this->siteId, RenderCacheType::Sitemap, 'p:1', 'chunk one');
        $cache->set($this->siteId, RenderCacheType::Sitemap, 'p:2', 'chunk two');

        $this->assertSame(3, $this->rowCount());
        $this->assertSame('master', $cache->get($this->siteId, RenderCacheType::Sitemap, null)?->content);
        $this->assertSame('chunk one', $cache->get($this->siteId, RenderCacheType::Sitemap, 'p:1')?->content);
        $this->assertSame('chunk two', $cache->get($this->siteId, RenderCacheType::Sitemap, 'p:2')?->content);
    }

    public function testInvalidateRemovesTheMasterEntry(): void
    {
        $cache = Plugin::getInstance()->renderCache;

        $cache->set($this->siteId, RenderCacheType::Sitemap, null, 'master');
        $cache->invalidate($this->siteId, RenderCacheType::Sitemap, null);

        $this->assertNull($cache->get($this->siteId, RenderCacheType::Sitemap, null));
    }

    private function rowCount(): int
    {
        return (int) RenderCacheRecord::find()
            ->where(['siteId' => $this->siteId, 'type' => RenderCacheType::Sitemap->value])
            ->count();
    }

    private function clear(): void
    {
        RenderCacheRecord::deleteAll(['siteId' => $this->siteId]);
    }
}
