<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\fields\BeaconSeoField;
use anvildev\beacon\variables\BeaconVariable;
use Craft;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\test\TestCase;
use DateTime;
use ReflectionObject;

/** @group requires-craft */
class BeaconVariableResolveSchemasIntegrationTest extends TestCase
{
    public function testResolveSchemasReturnsNodesForMatchedEntry(): void
    {
        if (!class_exists(\Craft::class) || Craft::$app === null) {
            $this->markTestSkipped('Requires initialized Craft app.');
        }

        $entry = $this->createLiveEntry();
        Craft::$app->getUrlManager()->setMatchedElement($entry);

        $variable = new BeaconVariable();
        $schemas = $this->invokeResolveSchemas($variable);

        $this->assertIsArray($schemas);
        $this->assertNotEmpty($schemas);
        $types = array_values(array_filter(array_map(
            static fn(array $node): ?string => is_string($node['@type'] ?? null) ? $node['@type'] : null,
            $schemas,
        )));
        $this->assertNotEmpty($types, 'expected at least one JSON-LD node with @type');
    }

    private function createLiveEntry(): Entry
    {
        $fields = Craft::$app->getFields();
        $field = $fields->getFieldByHandle('seo');
        if (!$field instanceof BeaconSeoField) {
            $field = new BeaconSeoField();
            $field->name = 'SEO';
            $field->handle = 'seo';
            $this->assertTrue($fields->saveField($field), 'save field: ' . json_encode($field->getErrors()));
        }

        $entries = Craft::$app->getEntries();
        $entryType = new EntryType();
        $entryType->name = 'Schema Page';
        $entryType->handle = 'schemaPage' . uniqid();
        $layout = new FieldLayout();
        $layout->type = Entry::class;
        $layout->setTabs([
            [
                'name' => 'Content',
                'elements' => [
                    ['type' => CustomField::class, 'fieldUid' => $field->uid],
                ],
            ],
        ]);
        $entryType->setFieldLayout($layout);
        $this->assertTrue($entries->saveEntryType($entryType), 'save entry type: ' . json_encode($entryType->getErrors()));

        $site = Craft::$app->getSites()->getPrimarySite();
        $section = new Section();
        $section->name = 'Schema Pages';
        $section->handle = 'schemaPages' . uniqid();
        $section->type = Section::TYPE_CHANNEL;
        $section->setSiteSettings([
            new Section_SiteSettings([
                'siteId' => $site->id,
                'enabledByDefault' => true,
                'hasUrls' => true,
                'uriFormat' => 'schema/{slug}',
            ]),
        ]);
        $section->setEntryTypes([$entryType]);
        $this->assertTrue($entries->saveSection($section), 'save section: ' . json_encode($section->getErrors()));

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;
        $entry->siteId = $site->id;
        $entry->title = 'Schema Entry';
        $entry->slug = 'schema-entry-' . uniqid();
        $entry->enabled = true;
        $entry->postDate = new DateTime('-1 hour');
        $entry->setFieldValue('seo', ['title' => 'Schema Entry']);
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry), 'save entry: ' . json_encode($entry->getErrors()));

        return $entry;
    }

    /** @return list<array<string,mixed>> */
    private function invokeResolveSchemas(BeaconVariable $variable): array
    {
        $ref = new ReflectionObject($variable);
        $method = $ref->getMethod('resolveSchemas');
        $method->setAccessible(true);
        return $method->invoke($variable);
    }
}
