<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\Plugin;
use anvildev\beacon\records\IndexNowSubmissionRecord;
use anvildev\beacon\services\IndexNowService;
use Craft;
use craft\models\Site;
use craft\test\TestCase;
use ReflectionObject;

/** @group requires-craft */
class IndexNowServiceIntegrationTest extends TestCase
{
    public function testRecentCountsEmptyOnFreshDb(): void
    {
        if (!class_exists(\Craft::class) || Craft::$app === null || Plugin::getInstance() === null) {
            $this->markTestSkipped('Requires initialized Craft + plugin instance.');
        }

        $counts = Plugin::getInstance()->indexNow->recentCounts(24);
        $this->assertSame(['ok' => 0, 'failed' => 0, 'total' => 0], $counts);
    }

    public function testRecordSubmissionAppearsInRecentSubmissions(): void
    {
        if (!class_exists(\Craft::class) || Craft::$app === null) {
            $this->markTestSkipped('Requires initialized Craft app.');
        }

        $site = Craft::$app->getSites()->getPrimarySite();
        $service = new IndexNowService();
        $this->invokeRecordSubmission($service, $site, ['https://example.test/a', 'https://example.test/b'], 200, true, null);

        $rows = $service->recentSubmissions(5, (int) $site->id);
        $this->assertNotEmpty($rows);
        $this->assertSame(2, $rows[0]['urlCount']);
        $this->assertSame('https://example.test/a', $rows[0]['firstUrl']);
        $this->assertTrue($rows[0]['succeeded']);
        $this->assertSame(200, $rows[0]['statusCode']);

        $this->assertGreaterThanOrEqual(1, IndexNowSubmissionRecord::find()->where(['siteId' => $site->id])->count());
    }

    /**
     * @param list<string> $urls
     */
    private function invokeRecordSubmission(
        IndexNowService $service,
        Site $site,
        array $urls,
        ?int $statusCode,
        bool $succeeded,
        ?string $note,
    ): void {
        $ref = new ReflectionObject($service);
        $method = $ref->getMethod('recordSubmission');
        $method->setAccessible(true);
        $method->invoke($service, $site, $urls, $statusCode, $succeeded, $note);
    }
}
