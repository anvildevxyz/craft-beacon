<?php

namespace anvildev\beacon\helpers;

use anvildev\beacon\enums\GeoScorePillar;
use anvildev\beacon\models\GeoPillarScore;
use anvildev\beacon\models\GeoScore;
use anvildev\beacon\records\GeoScoreRecord;
use craft\helpers\Json;
use DateTimeImmutable;

/**
 * Hydrates cached GEO scores from the score table without going through
 * {@see \anvildev\beacon\Plugin}, so GraphQL resolvers can read scores
 * without importing the plugin hub.
 */
final class GeoScoreLookup
{
    public static function forElement(int $elementId, int $siteId, ?string $expectedHash = null): ?GeoScore
    {
        if ($elementId <= 0) {
            return null;
        }
        $record = GeoScoreRecord::findOne(['elementId' => $elementId, 'siteId' => $siteId]);
        if ($record === null) {
            return null;
        }
        if ($expectedHash !== null && $record->sourceHash !== $expectedHash) {
            return null;
        }

        $decoded = Json::decodeIfJson($record->pillars);
        $pillars = [];
        if (is_array($decoded)) {
            foreach ($decoded as $handle => $raw) {
                if (!is_string($handle) || !is_array($raw)) {
                    continue;
                }
                $pillars[$handle] = new GeoPillarScore(
                    // Built-in handles rehydrate to the enum; custom handles keep
                    // the string so third-party pillars survive a cache round-trip.
                    pillar: GeoScorePillar::tryFrom($handle) ?? $handle,
                    score: (int) ($raw['score'] ?? 0),
                    band: (string) ($raw['band'] ?? GeoPillarScore::BAND_STALE),
                    notes: array_values(array_filter((array) ($raw['notes'] ?? []), is_string(...))),
                    debug: is_array($raw['debug'] ?? null) ? $raw['debug'] : [],
                );
            }
        }

        return new GeoScore(
            score: (int) $record->score,
            pillars: $pillars,
            computedAt: new DateTimeImmutable($record->computedAt),
        );
    }
}
