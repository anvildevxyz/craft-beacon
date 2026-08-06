<?php

namespace anvildev\beacon\gql\types;

use GraphQL\Type\Definition\Type;

/**
 * Result of the `beaconTrack404` mutation — mirrors what Beacon's native
 * 404 listener does: either a redirect matched (hit counter bumped) or the
 * URI was recorded in the 404 log.
 *
 * @phpstan-import-type GqlFieldDefinitionMap from \anvildev\beacon\gql\types\BeaconObjectType
 */
class BeaconTrack404PayloadType extends BeaconObjectType
{
    public static function getName(): string
    {
        return 'BeaconTrack404Payload';
    }

    protected static function getDescription(): string
    {
        return 'Outcome of tracking a 404: the matched redirect (hit recorded), or whether the URI was logged.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'redirect' => [
                'type' => BeaconResolvedRedirectType::getType(),
                'description' => 'The matched redirect (its hit counter has been incremented), or null when nothing matched.',
            ],
            'logged' => [
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'True when the URI was recorded in the 404 log (no redirect matched, 404 logging enabled, not filtered as a bot).',
            ],
        ];
    }
}
