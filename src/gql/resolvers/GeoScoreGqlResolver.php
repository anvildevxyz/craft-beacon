<?php

namespace anvildev\beacon\gql\resolvers;

use anvildev\beacon\helpers\GeoScoreLookup;
use anvildev\beacon\models\GeoScore;
use craft\helpers\Gql as GqlHelper;

/**
 * Lazy GraphQL resolver for the `geoScore` field on {@see \anvildev\beacon\gql\types\SeoMetaType}.
 *
 * @phpstan-import-type BeaconGeoPillarScoreGql from \anvildev\beacon\gql\types\BeaconGeoScoreType
 * @phpstan-import-type BeaconGeoScoreGql from \anvildev\beacon\gql\types\BeaconGeoScoreType
 * @phpstan-import-type GeoScoreGqlSource from \anvildev\beacon\gql\GqlArrayShapes
 */
final class GeoScoreGqlResolver
{
    /**
     * @param GeoScoreGqlSource $source
     * @return BeaconGeoScoreGql|null
     */
    public static function resolve(array $source): ?array
    {
        if (!GqlHelper::canSchema('beaconGeoScore', 'read')) {
            return null;
        }

        $elementId = (int) ($source['__beaconElementId'] ?? 0);
        $siteId = (int) ($source['__beaconSiteId'] ?? 0);
        if ($elementId <= 0 || $siteId <= 0) {
            return null;
        }

        $score = GeoScoreLookup::forElement($elementId, $siteId);
        if (!$score instanceof GeoScore) {
            return null;
        }

        return [
            'score' => $score->score,
            'weakestPillar' => $score->weakestPillar()?->value,
            'pillars' => array_values(array_map(
                static fn($pillar): array => [
                    'handle' => $pillar->pillarHandle(),
                    'score' => $pillar->score,
                    'band' => $pillar->band,
                    'notes' => $pillar->notes,
                ],
                $score->pillars,
            )),
            'computedAt' => $score->computedAt->format(\DATE_ATOM),
        ];
    }
}
