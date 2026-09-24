<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\controllers\FeedController;
use Craft;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\test\TestCase;
use DateTime;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * @group requires-craft
 */
class FeedControllerPolicyTest extends TestCase
{
    public function testActionAtomReturnsNotFoundForSingleSection(): void
    {
        $handle = $this->createSection(Section::TYPE_SINGLE, 'feedSinglePolicy');

        $controller = new FeedController('feed', Craft::$app);
        $this->expectException(NotFoundHttpException::class);
        $controller->actionAtom($handle);
    }

    public function testActionJsonReturnsNotFoundForSingleSection(): void
    {
        $handle = $this->createSection(Section::TYPE_SINGLE, 'feedSinglePolicyJson');

        $controller = new FeedController('feed', Craft::$app);
        $this->expectException(NotFoundHttpException::class);
        $controller->actionJson($handle);
    }

    public function testFeedResponsesCarryNoindexRobotsTag(): void
    {
        $handle = $this->createSection(Section::TYPE_CHANNEL, 'feedNoindexPolicy');
        $this->createLiveEntry($handle);

        $controller = new FeedController('feed', Craft::$app);

        $atom = $controller->actionAtom($handle);
        $this->assertInstanceOf(Response::class, $atom);
        $this->assertSame('noindex, nofollow', $atom->headers->get('X-Robots-Tag'));

        $json = $controller->actionJson($handle);
        $this->assertInstanceOf(Response::class, $json);
        $this->assertSame('noindex, nofollow', $json->headers->get('X-Robots-Tag'));
    }

    private function createSection(string $type, string $handle): string
    {
        $entries = Craft::$app->getEntries();

        $entryType = new EntryType();
        $entryType->name = $handle;
        $entryType->handle = $handle;
        $this->assertTrue($entries->saveEntryType($entryType), 'save entry type: ' . json_encode($entryType->getErrors()));

        $site = Craft::$app->getSites()->getPrimarySite();
        $section = new Section();
        $section->name = $handle;
        $section->handle = $handle;
        $section->type = $type;
        $section->setSiteSettings([
            new Section_SiteSettings([
                'siteId' => $site->id,
                'enabledByDefault' => true,
                'hasUrls' => false,
            ]),
        ]);
        $section->setEntryTypes([$entryType]);
        $this->assertTrue($entries->saveSection($section), 'save section: ' . json_encode($section->getErrors()));

        return $handle;
    }

    private function createLiveEntry(string $sectionHandle): Entry
    {
        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);
        $this->assertInstanceOf(Section::class, $section);
        $entryType = $section->getEntryTypes()[0];
        $site = Craft::$app->getSites()->getPrimarySite();

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;
        $entry->siteId = $site->id;
        $entry->title = 'Feed Policy Entry';
        $entry->slug = 'feed-policy-entry';
        $entry->enabled = true;
        $entry->postDate = new DateTime('-1 hour');
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry), 'save entry: ' . json_encode($entry->getErrors()));

        return $entry;
    }
}
