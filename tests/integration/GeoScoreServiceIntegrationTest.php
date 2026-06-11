<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\fields\BeaconSeoField;
use anvildev\beacon\Plugin;
use anvildev\beacon\records\GeoScoreRecord;
use Craft;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\test\TestCase;
use DateTime;

/** @group requires-craft */
class GeoScoreServiceIntegrationTest extends TestCase
{
    public function testComputePersistsAndForElementReadsBack(): void
    {
        if (!class_exists(\Craft::class) || Craft::$app === null || Plugin::getInstance() === null) {
            $this->markTestSkipped('Requires initialized Craft + plugin instance.');
        }

        $entry = $this->createLiveEntry();
        $siteId = (int) $entry->siteId;
        $geoScore = Plugin::getInstance()->geoScore;

        $this->assertNull($geoScore->forElement((int) $entry->id, $siteId));

        $computed = $geoScore->compute($entry, $siteId, persist: true);
        $this->assertGreaterThanOrEqual(0, $computed->score);
        $this->assertNotEmpty($computed->pillars);

        $cached = $geoScore->forElement((int) $entry->id, $siteId);
        $this->assertNotNull($cached);
        $this->assertSame($computed->score, $cached->score);

        $row = GeoScoreRecord::findOne(['elementId' => $entry->id, 'siteId' => $siteId]);
        $this->assertNotNull($row);
        $this->assertSame($computed->score, (int) $row->score);
    }

    public function testInvalidateRemovesPersistedRow(): void
    {
        if (!class_exists(\Craft::class) || Craft::$app === null || Plugin::getInstance() === null) {
            $this->markTestSkipped('Requires initialized Craft + plugin instance.');
        }

        $entry = $this->createLiveEntry();
        $siteId = (int) $entry->siteId;
        $geoScore = Plugin::getInstance()->geoScore;
        $geoScore->compute($entry, $siteId, persist: true);

        $geoScore->invalidate((int) $entry->id, $siteId);

        $this->assertNull($geoScore->forElement((int) $entry->id, $siteId));
        $this->assertNull(GeoScoreRecord::findOne(['elementId' => $entry->id, 'siteId' => $siteId]));
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
        $entryType->name = 'Geo Score Page';
        $entryType->handle = 'geoScorePage';
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
        $section->name = 'Geo Score Pages';
        $section->handle = 'geoScorePages';
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
        $entry->title = 'Scored Entry';
        $entry->slug = 'scored-entry-' . uniqid();
        $entry->enabled = true;
        $entry->postDate = new DateTime('-1 hour');
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry), 'save entry: ' . json_encode($entry->getErrors()));

        return $entry;
    }
}
