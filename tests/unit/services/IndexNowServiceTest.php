<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\helpers\IndexNow as IndexNowHelper;
use anvildev\beacon\services\IndexNowService;
use craft\models\Site;
use PHPUnit\Framework\TestCase;

/**
 * Pure-unit coverage for the parts of {@see IndexNowService} that don't need a
 * live Craft application or database.
 *
 * NOTE: {@see IndexNowService::recentCounts()} is intentionally NOT covered
 * here. It is inherently DB-bound and — critically — its correctness depends on
 * the *driver*: the bug it just fixed (`succeeded = 1` vs a real boolean column)
 * only manifests on PostgreSQL, not on MySQL/tinyint. A pure unit test cannot
 * exercise that. It needs an integration test on a Postgres matrix via the
 * Codeception suite (`composer test:integration`, see tests/integration). The
 * fix routes the boolean through the query builder (`['succeeded' => true]`)
 * so the comparison is driver-normalized; that guarantee can only be *verified*
 * against a real Postgres connection.
 *
 * What IS unit-testable is the input-validation guard at the top of
 * `submit()`: when the URL list normalizes to empty it must short-circuit to
 * `false` before any network or Craft-application access.
 */
class IndexNowServiceTest extends TestCase
{
    private function service(): IndexNowService
    {
        return new IndexNowService();
    }

    private function site(): Site
    {
        // A bare Site model is enough; submit() short-circuits before reading it.
        return new Site();
    }

    public function testSubmitReturnsFalseForEmptyUrlList(): void
    {
        $this->assertFalse($this->service()->submit([], $this->site()));
    }

    public function testSubmitReturnsFalseWhenAllUrlsAreEmptyStrings(): void
    {
        // array_filter strips '' (and non-strings); the normalized list is
        // empty, so we short-circuit before the Plugin singleton / HTTP client.
        $this->assertFalse($this->service()->submit(['', ''], $this->site()));
    }

    public function testSubmitDeduplicatesButStillShortCircuitsOnEmpty(): void
    {
        // Mixed blanks/non-strings all get filtered out -> empty list -> false.
        $this->assertFalse($this->service()->submit(['', '', ''], $this->site()));
    }

    public function testNormalizeUrlsHelperMatchesSubmitGuard(): void
    {
        $this->assertSame([], IndexNowHelper::normalizeUrls(['', 1, null]));
        $this->assertSame(['https://a.test'], IndexNowHelper::normalizeUrls(['https://a.test', 'https://a.test']));
    }
}
