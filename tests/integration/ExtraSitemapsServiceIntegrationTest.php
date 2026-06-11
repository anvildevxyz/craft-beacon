<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\fields\BeaconSeoField;
use anvildev\beacon\Plugin;
use anvildev\beacon\services\ExtraSitemapsService;
use Craft;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\test\TestCase;
use DateTime;

/** @group requires-craft */
class ExtraSitemapsServiceIntegrationTest extends TestCase
{
    public function testRenderNewsReturnsNullForEmptySectionList(): void
    {
        if (!class_exists(\Craft::class) || Craft::$app === null || Plugin::getInstance() === null) {
            $this->markTestSkipped('Requires initialized Craft + plugin instance.');
        }

        $service = Plugin::getInstance()->extraSitemaps;
        $this->assertInstanceOf(ExtraSitemapsService::class, $service);
        $this->assertNull($service->renderNews(1, [], 'Site', 'en'));
    }

    public function testRenderNewsIncludesRecentLiveEntry(): void
    {
        if (!class_exists(\Craft::class) || Craft::$app === null || Plugin::getInstance() === null) {
            $this->markTestSkipped('Requires initialized Craft + plugin instance.');
        }

        $site = Craft::$app->getSites()->getPrimarySite();
        $section = $this->createNewsSection($site->id);
        $entry = $this->createNewsEntry($section, 'Breaking Story');

        $xml = Plugin::getInstance()->extraSitemaps->renderNews(
            (int) $site->id,
            [$section->handle],
            'Beacon News',
            'en',
        );

        $this->assertIsString($xml);
        $this->assertStringContainsString('<news:title>Breaking Story</news:title>', $xml);
        $this->assertStringContainsString('<news:name>Beacon News</news:name>', $xml);
        $this->assertStringContainsString((string) $entry->getUrl(), $xml);
    }

    public function testRenderImageReturnsNullWhenNoMediaRelations(): void
    {
        if (!class_exists(\Craft::class) || Craft::$app === null || Plugin::getInstance() === null) {
            $this->markTestSkipped('Requires initialized Craft + plugin instance.');
        }

        $site = Craft::$app->getSites()->getPrimarySite();
        $section = $this->createNewsSection($site->id);
        $this->createNewsEntry($section, 'Text Only');

        $this->assertNull(Plugin::getInstance()->extraSitemaps->renderImage((int) $site->id, [$section->handle]));
    }

    private function createNewsSection(int $siteId): Section
    {
        $fields = Craft::$app->getFields();
        $seoField = $fields->getFieldByHandle('seo');
        if (!$seoField instanceof BeaconSeoField) {
            $seoField = new BeaconSeoField();
            $seoField->name = 'SEO';
            $seoField->handle = 'seo';
            $this->assertTrue($fields->saveField($seoField), 'save field: ' . json_encode($seoField->getErrors()));
        }

        $entries = Craft::$app->getEntries();
        $entryType = new EntryType();
        $entryType->name = 'News Article';
        $entryType->handle = 'newsArticle' . uniqid();
        $layout = new FieldLayout();
        $layout->type = Entry::class;
        $layout->setTabs([
            [
                'name' => 'Content',
                'elements' => [
                    new EntryTitleField(),
                    ['type' => CustomField::class, 'fieldUid' => $seoField->uid],
                ],
            ],
        ]);
        $entryType->setFieldLayout($layout);
        $this->assertTrue($entries->saveEntryType($entryType), 'save entry type: ' . json_encode($entryType->getErrors()));

        $section = new Section();
        $section->name = 'News';
        $section->handle = 'news' . uniqid();
        $section->type = Section::TYPE_CHANNEL;
        $section->setSiteSettings([
            new Section_SiteSettings([
                'siteId' => $siteId,
                'enabledByDefault' => true,
                'hasUrls' => true,
                'uriFormat' => 'news/{slug}',
            ]),
        ]);
        $section->setEntryTypes([$entryType]);
        $this->assertTrue($entries->saveSection($section), 'save section: ' . json_encode($section->getErrors()));

        return $section;
    }

    private function createNewsEntry(Section $section, string $title): Entry
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $section->getEntryTypes()[0]->id;
        $entry->siteId = $site->id;
        $entry->title = $title;
        $entry->slug = strtolower(preg_replace('/\W+/', '-', $title)) . '-' . uniqid();
        $entry->enabled = true;
        $entry->postDate = new DateTime('-30 minutes');
        $entry->setFieldValue('seo', ['title' => $title]);
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry), 'save entry: ' . json_encode($entry->getErrors()));

        return $entry;
    }
}
