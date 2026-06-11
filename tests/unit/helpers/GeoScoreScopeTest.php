<?php

namespace anvildev\beacon\tests\unit\helpers;

use anvildev\beacon\helpers\GeoScoreScope;
use craft\elements\Entry;
use craft\models\Section;
use PHPUnit\Framework\TestCase;

class GeoScoreScopeTest extends TestCase
{
    public function testSectionInScopeEmptyAllowlistMeansAll(): void
    {
        $this->assertTrue(GeoScoreScope::sectionInScope('blog', []));
    }

    public function testSectionInScopeRespectsAllowlist(): void
    {
        $this->assertTrue(GeoScoreScope::sectionInScope('blog', ['blog', 'news']));
        $this->assertFalse(GeoScoreScope::sectionInScope('docs', ['blog', 'news']));
        $this->assertFalse(GeoScoreScope::sectionInScope(null, ['blog']));
    }

    public function testEntryEligibleForChipRequiresIdSiteAndEnabled(): void
    {
        $entry = $this->mockEntry('blog', id: 1, siteId: 1);

        $this->assertTrue(GeoScoreScope::entryEligibleForChip($entry, true, []));
        $this->assertFalse(GeoScoreScope::entryEligibleForChip($entry, false, []));
        $this->assertFalse(GeoScoreScope::entryEligibleForChip(null, true, []));
    }

    public function testEntryEligibleForChipRespectsSectionAllowlist(): void
    {
        $blog = $this->mockEntry('blog', id: 1, siteId: 1);
        $docs = $this->mockEntry('docs', id: 2, siteId: 1);

        $this->assertTrue(GeoScoreScope::entryEligibleForChip($blog, true, ['blog']));
        $this->assertFalse(GeoScoreScope::entryEligibleForChip($docs, true, ['blog']));
    }

    private function mockEntry(string $sectionHandle, int $id, int $siteId): Entry
    {
        /** @var Entry&\PHPUnit\Framework\MockObject\MockObject $entry */
        $entry = $this->getMockBuilder(Entry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSection'])
            ->getMock();
        $entry->id = $id;
        $entry->siteId = $siteId;
        $entry->method('getSection')->willReturn(new Section(['handle' => $sectionHandle]));
        return $entry;
    }
}
