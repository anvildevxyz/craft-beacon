<?php

namespace anvildev\beacon\tests\unit\gql;

use anvildev\beacon\gql\types\BeaconRedirect404Type;
use anvildev\beacon\gql\types\BeaconRedirectType;
use anvildev\beacon\gql\types\BeaconShortLinkType;
use PHPUnit\Framework\TestCase;

/**
 * Field-definition tests for the GraphQL types. The schema-shape and
 * resolver tests need Craft's GqlEntityRegistry bootstrapped, so they
 * live in the integration suite.
 *
 * The field sets are the public contract for headless consumers — surface
 * a failing test if anyone removes one accidentally.
 */
class BeaconRedirectQueriesTest extends TestCase
{
    public function testRedirectTypeFieldDefinitionsAreStable(): void
    {
        $fields = BeaconRedirectType::getFieldDefinitions();
        $expected = [
            'id', 'propagationMethod', 'sourceUri', 'targetUri', 'statusCode', 'type',
            'queryStringMode', 'enabled', 'hits', 'lastHit', 'source',
            'sortOrder', 'elementId', 'note',
            'dateCreated', 'dateUpdated',
        ];
        foreach ($expected as $name) {
            $this->assertArrayHasKey($name, $fields, "Missing field: $name");
        }
    }

    public function testRedirect404TypeFieldDefinitionsAreStable(): void
    {
        $fields = BeaconRedirect404Type::getFieldDefinitions();
        foreach (['id', 'siteId', 'uri', 'hits', 'firstSeen', 'lastSeen', 'referer', 'handled'] as $name) {
            $this->assertArrayHasKey($name, $fields, "Missing field: $name");
        }
    }

    public function testRedirectTypeNameIsStable(): void
    {
        // Headless consumers query by type name in introspection — locking
        // it down so a rename here doesn't silently break clients.
        $this->assertSame('BeaconRedirect', BeaconRedirectType::getName());
        $this->assertSame('BeaconRedirect404', BeaconRedirect404Type::getName());
        $this->assertSame('BeaconShortLink', BeaconShortLinkType::getName());
    }

    public function testShortLinkTypeFieldDefinitionsAreStable(): void
    {
        $fields = BeaconShortLinkType::getFieldDefinitions();
        foreach (['id', 'propagationMethod', 'slug', 'destination', 'statusCode', 'enabled', 'clicks', 'lastClicked', 'expiresAt', 'note', 'dateCreated', 'dateUpdated'] as $name) {
            $this->assertArrayHasKey($name, $fields, "Missing field: $name");
        }
    }
}
