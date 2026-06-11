<?php

namespace anvildev\beacon\tests\unit\variables;

use anvildev\beacon\models\SeoMeta;
use anvildev\beacon\variables\BeaconVariable;
use PHPUnit\Framework\TestCase;
use ReflectionObject;
use Twig\Markup;

/**
 * Pins the Craft-absent contract of every guarded BeaconVariable entry point:
 * with no booted app (Craft::$app === null, Plugin::$plugin === null) each
 * method must degrade to its documented empty value instead of throwing.
 * This is the same environment targeted unit tests of head() rely on.
 */
class BeaconVariableCraftAbsentGuardsTest extends TestCase
{
    public function testBreadcrumbsReturnsEmptyList(): void
    {
        $this->assertSame([], (new BeaconVariable())->breadcrumbs());
    }

    public function testBodyStartAndBodyEndRenderEmptyMarkup(): void
    {
        $variable = new BeaconVariable();
        $this->assertSame('', (string) $variable->bodyStart());
        $this->assertSame('', (string) $variable->bodyEnd());
    }

    public function testTrackingForRendersEmptyMarkup(): void
    {
        $markup = (new BeaconVariable())->trackingFor('head', 'production');
        $this->assertInstanceOf(Markup::class, $markup);
        $this->assertSame('', (string) $markup);
    }

    public function testSocialsReturnsEmptyList(): void
    {
        $this->assertSame([], (new BeaconVariable())->socials());
    }

    public function testSocialUrlReturnsNull(): void
    {
        $this->assertNull((new BeaconVariable())->socialUrl('twitter'));
    }

    public function testBuildIdentitySchemaNodeReturnsNull(): void
    {
        $this->assertNull($this->invoke(new BeaconVariable(), 'buildIdentitySchemaNode'));
    }

    public function testBuildGeoProvenanceSchemaNodeReturnsNullForNullEntry(): void
    {
        $this->assertNull($this->invoke(new BeaconVariable(), 'buildGeoProvenanceSchemaNode', [null]));
    }

    public function testRenderSchemasForEntryReturnsEmptyForNullEntry(): void
    {
        $this->assertSame([], $this->invoke(new BeaconVariable(), 'renderSchemasForEntry', [null, null]));
    }

    public function testRenderEntryAddonsOnlyReturnsEmptyForNullEntry(): void
    {
        $this->assertSame([], $this->invoke(new BeaconVariable(), 'renderEntryAddonsOnly', [null]));
    }

    public function testExtractSeoFieldValueReturnsEmptyForNullElement(): void
    {
        $this->assertSame([], $this->invoke(new BeaconVariable(), 'extractSeoFieldValue', [null]));
    }

    public function testEmitServerTimingIsNoop(): void
    {
        $this->assertNull($this->invoke(new BeaconVariable(), 'emitServerTiming', [['resolve' => 1_000_000]]));
    }

    public function testMinimalHeadFallbackIsEmptyFragment(): void
    {
        $this->assertSame('', $this->invoke(new BeaconVariable(), 'minimalHeadFallback'));
    }

    public function testHeadDegradesToMinimalFallbackWhenRenderThrows(): void
    {
        $variable = new BeaconVariable();
        $meta = new SeoMeta();
        $meta->title = 'Page';
        // A malformed repeatable tag (array content) makes renderMetaTagMap()
        // throw a TypeError mid-render — head() must swallow it and degrade.
        /** @phpstan-ignore assign.propertyType (deliberately malformed tag to force the mid-render throw) */
        $meta->extraMetaTags = [['attr' => 'name', 'name' => 'broken', 'content' => ['not-a-string']]];
        $this->setProperty($variable, 'cachedMeta', $meta);

        $markup = $variable->head();

        $this->assertInstanceOf(Markup::class, $markup);
        $this->assertSame('', (string) $markup);
    }

    private function setProperty(object $obj, string $name, mixed $value): void
    {
        $prop = (new ReflectionObject($obj))->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($obj, $value);
    }

    /** @param array<int,mixed> $args */
    private function invoke(object $obj, string $name, array $args = []): mixed
    {
        $method = (new ReflectionObject($obj))->getMethod($name);
        $method->setAccessible(true);
        return $method->invokeArgs($obj, $args);
    }
}
