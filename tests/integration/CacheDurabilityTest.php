<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\helpers\RecordCache;
use anvildev\beacon\Plugin;
use anvildev\beacon\records\SettingsRecord;
use anvildev\beacon\records\ShortLinkRecord;
use Craft;
use craft\test\TestCase;
use yii\caching\CacheInterface;

/**
 * The cross-request cache trades a query for the risk of serving something
 * stale, so the failure modes that make a stale value *permanent* — a value
 * built across a concurrent write, or a cached "feature is off" answer that
 * nothing ever comes back to correct — need to stay closed.
 */
final class CacheDurabilityTest extends TestCase
{
    protected function _before(): void
    {
        parent::_before();
        Craft::$app->getCache()->flush();
    }

    protected function _after(): void
    {
        Craft::$app->getCache()->flush();
        parent::_after();
    }

    /**
     * A write that lands while a cold rebuild is in flight bumps the tag. The
     * value being built is already stale by the time it is stored, so storing
     * it against the post-write tag version would mark stale data fresh — and
     * nothing would fix it until an unrelated write happened to bump the tag
     * again.
     */
    public function testValueBuiltAcrossAWriteIsNotCached(): void
    {
        $builds = 0;
        $raceNextBuild = true;

        $build = function() use (&$builds, &$raceNextBuild): string {
            $builds++;
            if ($raceNextBuild) {
                $raceNextBuild = false;
                // Stands in for a concurrent request saving the record.
                SettingsRecord::invalidateCacheTag();
            }

            return 'value-' . $builds;
        };

        $remember = fn(): string => RecordCache::remember(
            'test.race',
            [SettingsRecord::CACHE_TAG],
            $build,
        );

        $this->assertSame('value-1', $remember());
        $this->assertSame('value-2', $remember(), 'a raced build must not have been cached');
        $this->assertSame('value-2', $remember(), 'an unraced build must be cached');
        $this->assertSame(2, $builds);
    }

    /**
     * The gate suppresses short-link resolution entirely, so a cached `0` is a
     * kill switch for the feature. Tag invalidation alone cannot be trusted to
     * lift it — the tag may be bumped on another node's cache, or from inside
     * the still-open transaction of the first short link's save — so the
     * negative answer has to expire on its own. The positive answer carries no
     * such risk and keeps the full duration.
     */
    public function testNegativeGateResultExpiresButPositiveDoesNot(): void
    {
        ShortLinkRecord::deleteAll();

        $durations = $this->recordCacheDurations(static function(): void {
            Plugin::getInstance()->shortLinks->anyExist();
        });

        $this->assertArrayHasKey('beacon.shortLinks.any', $durations);
        $negative = $durations['beacon.shortLinks.any'];
        $this->assertNotNull($negative, 'a cached "no short links" answer must expire on its own');
        $this->assertGreaterThan(0, $negative);
        $this->assertLessThanOrEqual(900, $negative, 'the feature must not stay off for long');

        $element = new \anvildev\beacon\elements\ShortLinkElement();
        $element->slug = 'ttl-probe';
        $element->destination = '/somewhere';
        $element->statusCode = 301;
        Craft::$app->getElements()->saveElement($element);

        Craft::$app->getCache()->flush();
        $durations = $this->recordCacheDurations(static function(): void {
            Plugin::getInstance()->shortLinks->anyExist();
        });

        $this->assertArrayHasKey('beacon.shortLinks.any', $durations);
        $this->assertNull(
            $durations['beacon.shortLinks.any'],
            'a positive result is safe to keep until the tag is bumped',
        );

        Craft::$app->getElements()->deleteElement($element, true);
    }

    /**
     * Craft's default cache is a file cache, i.e. this payload lands in
     * `storage/runtime/cache` — somewhere support bundles and dev syncs happily
     * carry off. The AI provider key must not be readable there.
     */
    public function testCachedSettingsHoldNoPlaintextApiKey(): void
    {
        $probe = 'sk-beacon-plaintext-probe';

        $record = SettingsRecord::findOne(1) ?? new SettingsRecord(['id' => 1]);
        $original = $record->aiApiKey;
        $record->aiApiKey = $probe;
        $record->save(false);

        Plugin::getInstance()->set('settings', \anvildev\beacon\services\SettingsService::class);
        $resolved = Plugin::getInstance()->settings->get()->aiApiKey;

        $cached = Craft::$app->getCache()->get(
            'beacon.' . (Plugin::getInstance()->getVersion() ?? 'dev') . '.settings.record',
        );

        $record->aiApiKey = $original;
        $record->save(false);

        $this->assertSame($probe, $resolved, 'the key must still resolve for the AI client');
        $this->assertNotFalse($cached, 'settings should have been cached');
        $this->assertStringNotContainsString(
            $probe,
            serialize($cached),
            'the API key must not be written to the cache in the clear',
        );
    }

    /**
     * Runs $work against a cache that records the duration passed to each
     * `set()`, then restores the real one.
     *
     * @return array<string, int|null>
     */
    private function recordCacheDurations(callable $work): array
    {
        $original = Craft::$app->getCache();

        $spy = new class extends \yii\caching\ArrayCache {
            /** @var array<string, int|null> */
            public array $durations = [];

            /**
             * @param mixed $key
             * @param mixed $value
             * @param int|null $duration
             * @param \yii\caching\Dependency|null $dependency
             */
            public function set($key, $value, $duration = null, $dependency = null): bool
            {
                if (is_string($key)) {
                    $this->durations[$key] = $duration;
                }

                return parent::set($key, $value, $duration, $dependency);
            }
        };

        Craft::$app->set('cache', $spy);

        try {
            $work();
        } finally {
            /** @var CacheInterface $original */
            Craft::$app->set('cache', $original);
        }

        return $spy->durations;
    }
}
