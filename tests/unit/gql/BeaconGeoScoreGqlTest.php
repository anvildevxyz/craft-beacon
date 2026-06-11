<?php

namespace anvildev\beacon\tests\unit\gql;

use anvildev\beacon\gql\resolvers\GeoScoreGqlResolver;
use anvildev\beacon\gql\types\BeaconGeoPillarScoreType;
use anvildev\beacon\gql\types\BeaconGeoScoreType;
use anvildev\beacon\gql\types\SeoMetaType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use PHPUnit\Framework\TestCase;

/**
 * Field-definition + resolver-shape coverage for the GraphQL surface of
 * PR-F. End-to-end query execution requires Craft + GqlEntityRegistry
 * bootstrap, so the assertions here lock the public contract — names,
 * fields, and lazy-resolver wiring — that headless consumers depend on.
 */
class BeaconGeoScoreGqlTest extends TestCase
{
    public function testTypeNamesAreStable(): void
    {
        // Locked: renaming either of these silently breaks every headless
        // client that queries `__type(name: "BeaconGeoScore")`.
        $this->assertSame('BeaconGeoScore', BeaconGeoScoreType::getName());
        $this->assertSame('BeaconGeoPillarScore', BeaconGeoPillarScoreType::getName());
    }

    public function testGeoScoreTypeFieldDefinitionsAreStable(): void
    {
        $this->skipWithoutCraft();

        $fields = BeaconGeoScoreType::getFieldDefinitions();
        foreach (['score', 'weakestPillar', 'pillars', 'computedAt'] as $name) {
            $this->assertArrayHasKey($name, $fields, "Missing field: $name");
        }
    }

    public function testGeoPillarScoreTypeFieldDefinitionsAreStable(): void
    {
        $fields = BeaconGeoPillarScoreType::getFieldDefinitions();
        foreach (['handle', 'score', 'band', 'notes'] as $name) {
            $this->assertArrayHasKey($name, $fields, "Missing field: $name");
        }
    }

    public function testQueryReturnsScore(): void
    {
        // The SeoMetaType wires the geoScore field with the right object
        // type — headless `entries(...) { beacon { geoScore { ... } } }`
        // queries resolve against this binding.
        $this->skipWithoutCraft();

        $fields = SeoMetaType::getFieldDefinitions();
        $this->assertArrayHasKey('geoScore', $fields);

        $type = $fields['geoScore']['type'];
        $unwrapped = $type instanceof NonNull ? $type->getWrappedType() : $type;
        $this->assertSame(BeaconGeoScoreType::getType(), $unwrapped);

        $pillarsField = BeaconGeoScoreType::getFieldDefinitions()['pillars'];
        $pillarsType = $pillarsField['type'];
        // NonNull(ListOf(NonNull(BeaconGeoPillarScore)))
        $this->assertInstanceOf(NonNull::class, $pillarsType);
        $listType = $pillarsType->getWrappedType();
        $this->assertInstanceOf(ListOfType::class, $listType);
        $itemType = $listType->getWrappedType();
        $this->assertInstanceOf(NonNull::class, $itemType);
        $this->assertSame(BeaconGeoPillarScoreType::getType(), $itemType->getWrappedType());
    }

    public function testLazyResolverSkipsWhenUnrequested(): void
    {
        // The geoScore field carries its own resolver — GraphQL only
        // invokes it when the field is in the selection set. That's the
        // mechanism that keeps the score table untouched for beacon
        // queries that don't ask for it.
        $this->skipWithoutCraft();

        $fields = SeoMetaType::getFieldDefinitions();
        $this->assertArrayHasKey('resolve', $fields['geoScore']);
        $this->assertIsCallable($fields['geoScore']['resolve'] ?? null);

        // And when the resolver runs against a source array that has no
        // entry context (e.g. a synthetic call from a non-Entry parent),
        // it short-circuits to null without hitting the service layer.
        $this->assertNull(GeoScoreGqlResolver::resolve([]));
        $this->assertNull(GeoScoreGqlResolver::resolve(['__beaconElementId' => 0, '__beaconSiteId' => 1]));
    }

    public function testWithoutScopeReturnsNull(): void
    {
        // The schema-gate test: without `beaconGeoScore:read` on the
        // active token, the resolver returns null regardless of input.
        // Requires Craft + a real GqlSchema fixture, which the unit
        // bootstrap doesn't provide — skipped here, covered by the
        // bench/gql-geoscore.sh end-to-end smoke against ddev.
        $this->skipWithoutCraft();

        $this->assertNull(GeoScoreGqlResolver::resolve(['__beaconElementId' => 1, '__beaconSiteId' => 1]));
    }

    private function skipWithoutCraft(): void
    {
        if (!$this->craftBootstrapped()) {
            $this->markTestSkipped('Requires initialized Craft (GqlEntityRegistry needs Craft::$app->getConfig()).');
        }
    }

    private function craftBootstrapped(): bool
    {
        return class_exists(\Craft::class) && \Craft::$app !== null;
    }
}
