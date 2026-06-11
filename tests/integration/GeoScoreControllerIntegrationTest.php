<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\controllers\GeoScoreController;
use anvildev\beacon\fields\BeaconSeoField;
use anvildev\beacon\jobs\RecomputeGeoScoreJob;
use anvildev\beacon\Plugin;
use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\test\TestCase;
use DateTime;
use yii\web\NotFoundHttpException;

/** @group requires-craft */
class GeoScoreControllerIntegrationTest extends TestCase
{
    public function testActionDrillDownThrowsWhenNoScoreExists(): void
    {
        if (!class_exists(\Craft::class) || Craft::$app === null) {
            $this->markTestSkipped('Requires initialized Craft app.');
        }

        $controller = new GeoScoreController('geo-score', Craft::$app);
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $this->expectException(NotFoundHttpException::class);
        $controller->actionDrillDown(PHP_INT_MAX, $siteId);
    }

    public function testActionStatusReturnsNotReadyWhenScoreMissing(): void
    {
        if (!class_exists(\Craft::class) || Craft::$app === null) {
            $this->markTestSkipped('Requires initialized Craft app.');
        }

        Craft::$app->getRequest()->headers->set('Accept', 'application/json');

        $controller = new GeoScoreController('geo-score', Craft::$app);
        $response = $controller->actionStatus(PHP_INT_MAX, Craft::$app->getSites()->getPrimarySite()->id);

        $this->assertSame(['ready' => false], $response->data);
    }

    public function testActionRecomputeQueuesJobAndInvalidatesScore(): void
    {
        if (!class_exists(\Craft::class) || Craft::$app === null || Plugin::getInstance() === null) {
            $this->markTestSkipped('Requires initialized Craft + plugin instance.');
        }

        $entry = $this->createLiveEntry();
        $elementId = (int) $entry->id;
        $siteId = (int) $entry->siteId;
        $geoScore = Plugin::getInstance()->geoScore;
        $geoScore->compute($entry, $siteId, persist: true);
        $this->assertNotNull($geoScore->forElement($elementId, $siteId));

        $before = (int) (new Query())->from('{{%queue}}')->count();
        $request = Craft::$app->getRequest();
        $request->headers->set('Accept', 'application/json');
        $request->setBodyParams(['elementId' => $elementId, 'siteId' => $siteId]);
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $controller = new GeoScoreController('geo-score', Craft::$app);
        $response = $controller->actionRecompute();

        $this->assertTrue($response->data['success'] ?? false);
        $this->assertNull($geoScore->forElement($elementId, $siteId), 'invalidate must drop cached row before job runs');
        $this->assertGreaterThan($before, (int) (new Query())->from('{{%queue}}')->count());

        $job = (new Query())
            ->from('{{%queue}}')
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertIsArray($job);
        $this->assertStringContainsString(RecomputeGeoScoreJob::class, (string) ($job['job'] ?? ''));
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
        $entryType->name = 'Recompute Page';
        $entryType->handle = 'recomputePage' . uniqid();
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
        $section->name = 'Recompute Pages';
        $section->handle = 'recomputePages' . uniqid();
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
        $entry->title = 'Recompute Entry';
        $entry->slug = 'recompute-entry-' . uniqid();
        $entry->enabled = true;
        $entry->postDate = new DateTime('-1 hour');
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry), 'save entry: ' . json_encode($entry->getErrors()));

        return $entry;
    }
}
