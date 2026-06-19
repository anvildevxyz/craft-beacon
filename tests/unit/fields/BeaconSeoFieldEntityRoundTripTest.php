<?php

namespace anvildev\beacon\tests\unit\fields;

use anvildev\beacon\fields\BeaconSeoField;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Proves the Beacon SEO field actually persists linked Wikidata entities
 * through the real save path: posted form shape -> normalizeValue ->
 * serializeValue (DB string) -> normalizeValue again preserves the rows.
 *
 * Smoke testing could not confirm this via synthetic browser clicks, so this
 * locks the contract at the unit level. The field is instantiated without its
 * constructor because normalizeValue()/serializeValue() are pure (defaults are
 * constants; entities flow through EntitySchema::sanitize + Json) and don't
 * need a booted Craft application.
 */
class BeaconSeoFieldEntityRoundTripTest extends TestCase
{
    public function testEntitiesSurviveNormalizeSerializeRoundTrip(): void
    {
        $field = $this->field();

        $posted = ['entities' => [
            [
                'qid' => 'Q110260868',
                'label' => 'Craft CMS',
                'description' => 'content management system',
                'wikidataUrl' => 'https://www.wikidata.org/wiki/Q110260868',
                'wikipediaUrl' => 'https://en.wikipedia.org/wiki/Craft_CMS',
                'officialUrl' => 'https://craftcms.com/',
                'role' => 'about',
            ],
        ]];

        $normalized = $field->normalizeValue($posted);
        $serialized = $field->serializeValue($normalized);
        $this->assertIsString($serialized);

        // Round-trip back through the field the way Craft does when reading the
        // stored DB value.
        $reloaded = $field->normalizeValue($serialized);

        $this->assertCount(1, $reloaded['entities']);
        $entity = $reloaded['entities'][0];
        $this->assertSame('Q110260868', $entity['qid']);
        $this->assertSame('Craft CMS', $entity['label']);
        $this->assertSame('about', $entity['role']);
        $this->assertSame('https://www.wikidata.org/wiki/Q110260868', $entity['wikidataUrl']);
        $this->assertSame('https://en.wikipedia.org/wiki/Craft_CMS', $entity['wikipediaUrl']);
        $this->assertSame('https://craftcms.com/', $entity['officialUrl']);
    }

    public function testMalformedEntityRowsAreDropped(): void
    {
        $field = $this->field();

        $posted = ['entities' => [
            // valid
            ['label' => 'Valid', 'role' => 'mentions', 'wikidataUrl' => 'https://www.wikidata.org/wiki/Q1'],
            // no label -> dropped
            ['label' => '', 'wikidataUrl' => 'https://www.wikidata.org/wiki/Q2'],
            // no linkable URL -> dropped
            ['label' => 'No URLs', 'wikidataUrl' => '', 'wikipediaUrl' => '', 'officialUrl' => ''],
        ]];

        $reloaded = $field->normalizeValue($field->serializeValue($field->normalizeValue($posted)));

        $this->assertCount(1, $reloaded['entities']);
        $this->assertSame('Valid', $reloaded['entities'][0]['label']);
        $this->assertSame('mentions', $reloaded['entities'][0]['role']);
    }

    public function testNoEntitiesKeyNormalizesToEmptyList(): void
    {
        $field = $this->field();
        $normalized = $field->normalizeValue([]);
        $this->assertSame([], $normalized['entities']);
    }

    private function field(): BeaconSeoField
    {
        // normalizeValue()/serializeValue() don't touch the Craft app, so skip
        // the Field constructor (which would).
        return (new ReflectionClass(BeaconSeoField::class))->newInstanceWithoutConstructor();
    }
}
