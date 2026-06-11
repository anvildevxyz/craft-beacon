<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\elements\RedirectElement;
use anvildev\beacon\enums\RedirectSource;
use anvildev\beacon\Plugin;
use Craft;
use craft\test\TestCase;

/** @group requires-craft */
class RedirectImporterIntegrationTest extends TestCase
{
    public function testImportFromCsvPersistsValidRows(): void
    {
        if (!class_exists(\Craft::class) || Craft::$app === null || Plugin::getInstance() === null) {
            $this->markTestSkipped('Requires initialized Craft + plugin instance.');
        }

        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $csv = "source,target,statusCode\n/csv-import-a,/dest-a,301\n/csv-import-b,/dest-b,302\n";

        $result = Plugin::getInstance()->redirectImporter->importFromCsv($csv, $siteId);

        $this->assertSame(2, $result->insertedCount);
        $this->assertSame(0, $result->skippedCount);
        $this->assertSame([], $result->errors);

        $redirect = RedirectElement::find()
            ->siteId($siteId)
            ->andWhere(['beacon_redirects.sourceUri' => '/csv-import-a'])
            ->one();
        $this->assertInstanceOf(RedirectElement::class, $redirect);
        $this->assertSame('/dest-a', $redirect->targetUri);
        $this->assertSame(301, $redirect->statusCode);
        $this->assertSame(RedirectSource::CsvImport->value, $redirect->source);
    }

    public function testImportFromCsvSkipsUnsafeTargetsAtParseTime(): void
    {
        if (!class_exists(\Craft::class) || Craft::$app === null || Plugin::getInstance() === null) {
            $this->markTestSkipped('Requires initialized Craft + plugin instance.');
        }

        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $csv = "source,target,statusCode\n/safe,/dest,301\n/bad,//evil.example,301\n";

        $result = Plugin::getInstance()->redirectImporter->importFromCsv($csv, $siteId);

        $this->assertSame(1, $result->insertedCount);
        $this->assertSame(1, $result->skippedCount);
        $this->assertNotNull(RedirectElement::find()->siteId($siteId)->andWhere(['beacon_redirects.sourceUri' => '/safe'])->one());
        $this->assertNull(RedirectElement::find()->siteId($siteId)->andWhere(['beacon_redirects.sourceUri' => '/bad'])->one());
    }
}
