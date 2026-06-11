<?php

namespace anvildev\beacon\services;

use anvildev\beacon\helpers\Http;
use Craft;
use yii\base\Component;
use yii\web\TooManyRequestsHttpException;

class GeoExportThrottleService extends Component
{
    private const DEFAULT_MAX_PER_MINUTE = 60;

    /**
     * @throws TooManyRequestsHttpException
     */
    public function enforce(string $bucket = 'geo_export', int $maxPerMinute = self::DEFAULT_MAX_PER_MINUTE): void
    {
        $ip = Http::request()->getUserIP() ?? 'unknown';

        $userId = Craft::$app->getUser()->getId();
        $identity = $userId !== null ? 'u' . $userId : 'anon';
        $cacheKey = $bucket . ':' . $identity . ':' . $ip;
        $cache = Craft::$app->getCache();
        $mutex = Craft::$app->getMutex();
        $mutexKey = 'beacon-throttle:' . $cacheKey;

        // Serialise the read-modify-write so concurrent requests can't all observe
        // the same pre-bump count, pass the threshold check, and write back the
        // same +1 value (effective limit becomes N+concurrency instead of N).
        if (!$mutex->acquire($mutexKey, 1)) {
            throw new TooManyRequestsHttpException('Rate limit exceeded.');
        }
        try {
            $count = (int) $cache->get($cacheKey);
            if ($count >= $maxPerMinute) {
                throw new TooManyRequestsHttpException('Rate limit exceeded.');
            }
            $cache->set($cacheKey, $count + 1, 60);
        } finally {
            $mutex->release($mutexKey);
        }
    }
}
