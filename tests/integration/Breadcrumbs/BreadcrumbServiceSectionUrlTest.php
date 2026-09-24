<?php

namespace anvildev\beacon\tests\integration\Breadcrumbs;

use anvildev\beacon\models\BreadcrumbSettings;
use anvildev\beacon\Plugin;
use Craft;
use craft\elements\Entry;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\test\TestCase;
use DateTime;

/**
 * The section item derived by BreadcrumbService must be an absolute URL
 * (site base URL + path), not the bare path deriveSectionPath() returns.
 *
 * @group requires-craft
 */
final class BreadcrumbServiceSectionUrlTest extends TestCase
{
    public function testSectionItemUrlIsAbsoluteAndCarriesSiteBasePrefix(): void
    {
        $entry = $this->createTopLevelEntry('breadcrumbSectionUrl', 'diensten/{slug}');

        $settings = new BreadcrumbSettings(siteId: (int) $entry->siteId, enabled: true, homeLabel: 'Home');
        $items = Plugin::getInstance()->breadcrumbs->resolve($entry, $settings, 'https://example.com/nl');

        $this->assertSame([
            ['name' => 'Home', 'url' => 'https://example.com/nl'],
            ['name' => 'breadcrumbSectionUrl', 'url' => 'https://example.com/nl/diensten'],
            ['name' => 'Breadcrumb Section Url Entry'],
        ], $items);
    }

    private function createTopLevelEntry(string $sectionHandle, string $uriFormat): Entry
    {
        $entries = Craft::$app->getEntries();

        $entryType = new EntryType();
        $entryType->name = $sectionHandle;
        $entryType->handle = $sectionHandle;
        $layout = new FieldLayout();
        $layout->type = Entry::class;
        $layout->setTabs([
            ['name' => 'Content', 'elements' => [new EntryTitleField()]],
        ]);
        $entryType->setFieldLayout($layout);
        $this->assertTrue($entries->saveEntryType($entryType), 'save entry type: ' . json_encode($entryType->getErrors()));

        $site = Craft::$app->getSites()->getPrimarySite();
        $section = new Section();
        $section->name = $sectionHandle;
        $section->handle = $sectionHandle;
        $section->type = Section::TYPE_CHANNEL;
        $section->setSiteSettings([
            new Section_SiteSettings([
                'siteId' => $site->id,
                'enabledByDefault' => true,
                'hasUrls' => true,
                'uriFormat' => $uriFormat,
                'template' => 'index',
            ]),
        ]);
        $section->setEntryTypes([$entryType]);
        $this->assertTrue($entries->saveSection($section), 'save section: ' . json_encode($section->getErrors()));

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;
        $entry->siteId = $site->id;
        $entry->title = 'Breadcrumb Section Url Entry';
        $entry->slug = 'breadcrumb-section-url-entry';
        $entry->enabled = true;
        $entry->postDate = new DateTime('-1 hour');
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry), 'save entry: ' . json_encode($entry->getErrors()));

        return $entry;
    }
}
