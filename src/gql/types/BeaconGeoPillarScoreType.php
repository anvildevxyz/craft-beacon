<?php

namespace anvildev\beacon\gql\types;

use GraphQL\Type\Definition\Type;

/** @phpstan-import-type GqlFieldDefinitionMap from \anvildev\beacon\gql\types\BeaconObjectType */
class BeaconGeoPillarScoreType extends BeaconObjectType
{
    public static function getName(): string
    {
        return 'BeaconGeoPillarScore';
    }

    protected static function getDescription(): string
    {
        return 'Per-pillar slice of a Beacon GEO score (0–10 with band + actionable notes).';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'handle' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Pillar handle: `freshnessBanding`, `entityCompleteness`, `claimBasedHeadings`, `chunkability`, `factDensity`, or `outboundCitationDensity`.',
            ],
            'score' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'Per-pillar score, 0–10.',
            ],
            'band' => [
                'type' => Type::nonNull(Type::string()),
                'description' => '`top` (8–10), `good` (5–7), `low` (2–4), or `stale` (0–1).',
            ],
            'notes' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
                'description' => 'Actionable feedback strings; rendered verbatim in the CP drill-down.',
            ],
        ];
    }
}
