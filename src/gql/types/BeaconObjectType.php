<?php

namespace anvildev\beacon\gql\types;

use craft\gql\base\ObjectType;
use craft\gql\GqlEntityRegistry;

/**
 * Base for Beacon GraphQL object types: registers the type once and wires its
 * field resolver, leaving subclasses to declare only name, description, and fields.
 *
 * Craft 6's legacy GQL bridge (`CraftCms\Yii2Adapter\Gql\LegacyGqlTypes`)
 * requires every type registered through the old `EVENT_REGISTER_GQL_TYPES`
 * event to implement `CraftCms\Cms\Gql\Contracts\SingularTypeInterface`, or
 * the whole schema (not just this plugin's fields) fails to build. That
 * interface doesn't exist at all on Craft 5, so `implements` can't
 * reference it directly in a class declaration this file defines
 * unconditionally on both platforms — PHP resolves an `implements` clause
 * at class-load time, regardless of whether the Craft-6-only bridge code
 * that actually checks it ever runs. Declaring the class twice, gated on
 * `interface_exists()`, is the standard PHP workaround: exactly one branch
 * below ever executes, so the other's reference to the interface is never
 * resolved.
 *
 * @phpstan-type GqlFieldDefinition array{type: \GraphQL\Type\Definition\Type, description?: string, resolve?: callable}
 * @phpstan-type GqlFieldDefinitionMap array<string, GqlFieldDefinition>
 */
if (interface_exists(\CraftCms\Cms\Gql\Contracts\SingularTypeInterface::class)) {
    abstract class BeaconObjectType extends ObjectType implements \CraftCms\Cms\Gql\Contracts\SingularTypeInterface
    {
        abstract public static function getName(): string;

        abstract protected static function getDescription(): string;

        /** @return GqlFieldDefinitionMap */
        abstract public static function getFieldDefinitions(): array;

        public static function getType(): \GraphQL\Type\Definition\ObjectType
        {
            $class = static::class;

            return GqlEntityRegistry::getOrCreate(static::getName(), fn() => new $class([
                'name' => static::getName(),
                'fields' => $class . '::getFieldDefinitions',
                'description' => static::getDescription(),
            ]));
        }
    }
} else {
    abstract class BeaconObjectType extends ObjectType
    {
        abstract public static function getName(): string;

        abstract protected static function getDescription(): string;

        /** @return GqlFieldDefinitionMap */
        abstract public static function getFieldDefinitions(): array;

        public static function getType(): \GraphQL\Type\Definition\ObjectType
        {
            $class = static::class;

            return GqlEntityRegistry::getOrCreate(static::getName(), fn() => new $class([
                'name' => static::getName(),
                'fields' => $class . '::getFieldDefinitions',
                'description' => static::getDescription(),
            ]));
        }
    }
}
