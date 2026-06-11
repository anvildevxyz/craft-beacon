<?php

namespace anvildev\beacon\tests\unit\fields;

use anvildev\beacon\fields\BeaconRedirectSourcesField;
use PHPUnit\Framework\TestCase;
use ReflectionObject;

/**
 * Pure-PHP tests for the field's normalization, serialization, and
 * cleanList contracts. DB-touching `afterElementSave` is exercised in
 * the integration suite alongside Craft bootstrap.
 */
class BeaconRedirectSourcesFieldTest extends TestCase
{
    public function testNormalizeAcceptsArray(): void
    {
        $field = new BeaconRedirectSourcesField();
        $result = $field->normalizeValue(['/old', '/legacy/foo']);
        $this->assertSame(['/old', '/legacy/foo'], $result);
    }

    public function testNormalizeDeduplicatesAndTrims(): void
    {
        $field = new BeaconRedirectSourcesField();
        $result = $field->normalizeValue(['/foo', ' /foo ', '/bar', '']);
        $this->assertSame(['/foo', '/bar'], $result);
    }

    public function testNormalizeDecodesJsonString(): void
    {
        $field = new BeaconRedirectSourcesField();
        $result = $field->normalizeValue('["/a", "/b"]');
        $this->assertSame(['/a', '/b'], $result);
    }

    public function testNormalizeReturnsEmptyForOpaqueSerializedValue(): void
    {
        // The DB column holds '1' as a placeholder — that doesn't decode
        // to an array, so an element without an id should yield [].
        $field = new BeaconRedirectSourcesField();
        $this->assertSame([], $field->normalizeValue('1'));
        $this->assertSame([], $field->normalizeValue(''));
        $this->assertSame([], $field->normalizeValue(null));
    }

    public function testSerializeAlwaysReturnsMarker(): void
    {
        // serializeValue is intentionally opaque — the DB column is a sentinel.
        $field = new BeaconRedirectSourcesField();
        $this->assertSame('1', $field->serializeValue(['/foo']));
        $this->assertSame('1', $field->serializeValue([]));
        $this->assertSame('1', $field->serializeValue('whatever'));
    }

    public function testCleanListSkipsNonStrings(): void
    {
        // The form may post unexpected types if tampered with — cleanList
        // must drop them silently instead of letting them reach the DB.
        $field = new BeaconRedirectSourcesField();
        $ref = new ReflectionObject($field);
        $m = $ref->getMethod('cleanList');
        $m->setAccessible(true);
        $result = $m->invoke($field, ['/ok', 42, ['nested'], '', '  /trimmed  ', null]);
        $this->assertSame(['/ok', '/trimmed'], $result);
    }

    public function testContentColumnTypeReservesAMinimalSentinel(): void
    {
        // The field type stores nothing meaningful in elements_sites — just
        // enough that Craft tracks "field has a value". Lock this contract
        // so a regression doesn't accidentally start persisting real data.
        $field = new BeaconRedirectSourcesField();
        $this->assertSame('string(1)', $field->getContentColumnType());
    }
}
