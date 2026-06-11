<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\fields\BeaconSeoField;
use anvildev\beacon\Plugin;
use anvildev\beacon\services\GeoMarkdownExportService;
use Craft;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\test\TestCase;
use DateTime;

/**
 * Noindex entries must 404 (not 403) from the GEO export controller.
 *
 * @group requires-craft
 */
class GeoExportNoindexTest extends TestCase
{
    public function testExportReturnsNullForNoindexEntry(): void
    {
        $settings = Plugin::getInstance()->settings->get();
        $settings->geoMarkdownEnabled = true;
        Plugin::getInstance()->settings->save($settings);

        $entry = $this->createLiveEntryWithSeo(['robots' => ['noindex' => true]]);
        $this->assertSame('live', $entry->getStatus(), 'fixture entry must be live for the export path');

        $service = Plugin::getInstance()->geoMarkdownExport;
        $this->assertInstanceOf(GeoMarkdownExportService::class, $service);
        $this->assertNull(
            $service->exportElement($entry),
            'noindex entries must not produce a Markdown body',
        );
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
        $entryType->name = 'Page';
        $entryType->handle = 'page';
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
        $section->name = 'Pages';
        $section->handle = 'pages';
        $section->type = Section::TYPE_CHANNEL;
        $section->setSiteSettings([
            new Section_SiteSettings([
                'siteId' => $site->id,
                'enabledByDefault' => true,
                'hasUrls' => false,
            ]),
        ]);
        $section->setEntryTypes([$entryType]);
        $this->assertTrue($entries->saveSection($section), 'save section: ' . json_encode($section->getErrors()));

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;
        $entry->siteId = $site->id;
        $entry->title = 'Test Page';
        $entry->slug = 'test-page';
        $entry->enabled = true;
        $entry->postDate = new DateTime('-1 hour');
        $entry->setFieldValue('seo', $seoValue);
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry), 'save entry: ' . json_encode($entry->getErrors()));

        return $entry;
    }
}
