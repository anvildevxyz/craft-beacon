<?php

namespace anvildev\beacon\gql\types;

use GraphQL\Type\Definition\Type;

/**
 * @phpstan-import-type GqlFieldDefinitionMap from \anvildev\beacon\gql\types\BeaconObjectType
 *
 * @phpstan-type BeaconGeoPillarScoreGql array{
 *     handle: string,
 *     score: int,
 *     band: string,
 *     notes: list<string>,
 * }
 * @phpstan-type BeaconGeoScoreGql array{
 *     score: int,
 *     weakestPillar: ?string,
 *     pillars: list<BeaconGeoPillarScoreGql>,
 *     computedAt: string,
 * }
 */
class BeaconGeoScoreType extends BeaconObjectType
{
    public static function getName(): string
    {
        return 'BeaconGeoScore';
    }

    protected static function getDescription(): string
    {
        return 'Composite Beacon GEO score for an entry (0–100 with per-pillar breakdown).';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'score' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'Composite score, 0–100. Calibrated to the published GEO research, not to your peers.',
            ],
            'weakestPillar' => [
                'type' => Type::string(),
                'description' => 'Handle of the lowest-scoring pillar (the one to act on first), or null when no pillars are registered.',
            ],
            'pillars' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(BeaconGeoPillarScoreType::getType()))),
                'description' => 'Per-pillar breakdown, ordered by the enum case order.',
            ],
            'computedAt' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'ISO-8601 timestamp of the most recent compute. May lag editor saves by one queue-runner cycle.',
            ],
        ];
    }
}
