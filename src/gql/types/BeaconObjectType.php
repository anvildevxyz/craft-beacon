<?php

namespace anvildev\beacon\gql\types;

use craft\gql\base\ObjectType;
use craft\gql\GqlEntityRegistry;

/**
 * Base for Beacon GraphQL object types: registers the type once and wires its
 * field resolver, leaving subclasses to declare only name, description, and fields.
 *
 * @phpstan-type GqlFieldDefinition array{type: \GraphQL\Type\Definition\Type, description?: string, resolve?: callable}
 * @phpstan-type GqlFieldDefinitionMap array<string, GqlFieldDefinition>
 */
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
