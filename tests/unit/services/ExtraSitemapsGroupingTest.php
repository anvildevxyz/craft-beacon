<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\services\ExtraSitemapsService;
use craft\elements\Asset;
use PHPUnit\Framework\TestCase;

/**
 * Covers the batched media-sitemap grouping that replaced the per-entry asset
 * query (the N+1 fix). The grouping must key each asset back to its source
 * entry, preserve relation sort order, and silently drop relations whose target
 * asset was filtered out (wrong kind / not on site).
 */
class ExtraSitemapsGroupingTest extends TestCase
{
    private function asset(int $id): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->id = $id;
        return $asset;
    }

    public function testGroupsAssetsUnderTheirSourceEntryPreservingOrder(): void
    {
        $a10 = $this->asset(10);
        $a11 = $this->asset(11);
        $a20 = $this->asset(20);
        $assetsById = [10 => $a10, 11 => $a11, 20 => $a20];

        // Relations are already ordered by sortOrder; entry 1 owns 10 then 11.
        $relations = [
            ['sourceId' => 1, 'targetId' => 10],
            ['sourceId' => 1, 'targetId' => 11],
            ['sourceId' => 2, 'targetId' => 20],
        ];

        $grouped = ExtraSitemapsService::groupAssetsBySource($relations, $assetsById);

        $this->assertSame([1, 2], array_keys($grouped));
        $this->assertSame([$a10, $a11], $grouped[1], 'assets must stay in relation order under their entry');
        $this->assertSame([$a20], $grouped[2]);
    }

    public function testDropsRelationsWhoseTargetWasFilteredOut(): void
    {
        // Entry 1 relates to 10 (a video, filtered out by ->kind('image')) and
        // 11 (kept). Only the loaded asset survives.
        $a11 = $this->asset(11);
        $relations = [
            ['sourceId' => 1, 'targetId' => 10],
            ['sourceId' => 1, 'targetId' => 11],
        ];

        $grouped = ExtraSitemapsService::groupAssetsBySource($relations, [11 => $a11]);

        $this->assertSame([$a11], $grouped[1]);
    }

    public function testEntryWithNoSurvivingAssetsIsAbsent(): void
    {
        $relations = [['sourceId' => 1, 'targetId' => 10]];

        $grouped = ExtraSitemapsService::groupAssetsBySource($relations, []);

        $this->assertSame([], $grouped, 'an entry whose every related asset was filtered out must not appear');
    }

    public function testHandlesStringIdsFromTheQueryBuilder(): void
    {
        // PDO returns column values as strings; grouping must normalise to int keys.
        $a10 = $this->asset(10);
        $relations = [['sourceId' => '1', 'targetId' => '10']];

        $grouped = ExtraSitemapsService::groupAssetsBySource($relations, [10 => $a10]);

        $this->assertSame([1], array_keys($grouped));
        $this->assertSame([$a10], $grouped[1]);
    }
}
