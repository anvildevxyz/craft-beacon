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
class BeaconVariableResolveMetaIntegrationTest extends TestCase
{
    public function testResolveMetaUsesSeoFieldValuesForMatchedEntry(): void
    {
        if (!class_exists(\Craft::class) || Craft::$app === null) {
            $this->markTestSkipped('Requires initialized Craft app.');
        }

        $entry = $this->createLiveEntryWithSeo([
            'title' => 'Integration Meta Title',
            'description' => 'Integration meta description',
        ]);

        Craft::$app->getUrlManager()->setMatchedElement($entry);

        $variable = new BeaconVariable();
        $meta = $this->invokeResolveMeta($variable);

        $this->assertSame('Integration Meta Title', $meta->title);
        $this->assertSame('Integration meta description', $meta->description);
    }

    /**
     * @param array<string,mixed> $seoValue
     */
    private function createLiveEntryWithSeo(array $seoValue): Entry
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
        $entryType->name = 'Resolve Meta Page';
        $entryType->handle = 'resolveMetaPage';
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
        $section->name = 'Resolve Meta Pages';
        $section->handle = 'resolveMetaPages';
        $section->type = Section::TYPE_CHANNEL;
        $section->setSiteSettings([
            new Section_SiteSettings([
                'siteId' => $site->id,
                'enabledByDefault' => true,
                'hasUrls' => true,
                'uriFormat' => 'pages/{slug}',
            ]),
        ]);
        $section->setEntryTypes([$entryType]);
        $this->assertTrue($entries->saveSection($section), 'save section: ' . json_encode($section->getErrors()));

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;
        $entry->siteId = $site->id;
        $entry->title = 'Resolve Meta Entry';
        $entry->slug = 'resolve-meta-' . uniqid();
        $entry->enabled = true;
        $entry->postDate = new DateTime('-1 hour');
        $entry->setFieldValue('seo', $seoValue);
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry), 'save entry: ' . json_encode($entry->getErrors()));

        return $entry;
    }

    private function invokeResolveMeta(BeaconVariable $variable): \anvildev\beacon\models\SeoMeta
    {
        $ref = new ReflectionObject($variable);
        $method = $ref->getMethod('resolveMeta');
        $method->setAccessible(true);
        return $method->invoke($variable);
    }
}
