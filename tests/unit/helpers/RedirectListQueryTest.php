<?php

namespace anvildev\beacon\tests\unit\helpers;

use anvildev\beacon\helpers\RedirectListQuery;
use anvildev\beacon\models\RedirectListFilters;
use PHPUnit\Framework\TestCase;

class RedirectListQueryTest extends TestCase
{
    public function testResolveBuildsSearchAndStatusFragments(): void
    {
        $filters = new RedirectListFilters(
            q: 'legacy',
            statusCode: '301',
            type: 'exact',
            enabled: '1',
            source: 'import',
            stale: '1',
            sort: 'manual',
        );

        $resolved = RedirectListQuery::resolve($filters, 90);

        $this->assertCount(5, $resolved['where']);
        $this->assertSame('enabled', $resolved['status']);
        $this->assertSame(
            ['beacon_redirects.sortOrder' => SORT_ASC, 'beacon_redirects.id' => SORT_ASC],
            $resolved['orderBy'],
        );
    }

    public function testResolveIgnoresStaleWhenThresholdMissing(): void
    {
        $filters = new RedirectListFilters(stale: '1');
        $resolved = RedirectListQuery::resolve($filters, null);

        $this->assertSame([], $resolved['where']);
        $this->assertNull($resolved['status']);
    }

    public function testSortOrderDefaultsToHitsDesc(): void
    {
        $this->assertSame(
            ['beacon_redirects.hits' => SORT_DESC, 'elements.dateUpdated' => SORT_DESC],
            RedirectListQuery::sortOrder('hits_desc'),
        );
        $this->assertSame(
            ['beacon_redirects.hits' => SORT_DESC, 'elements.dateUpdated' => SORT_DESC],
            RedirectListQuery::sortOrder('unknown'),
        );
    }
}
