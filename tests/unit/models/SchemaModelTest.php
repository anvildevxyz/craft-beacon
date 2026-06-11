<?php

namespace anvildev\beacon\tests\unit\models;

use anvildev\beacon\models\Schema;
use anvildev\beacon\models\Settings;
use PHPUnit\Framework\TestCase;

class SchemaModelTest extends TestCase
{
    public function testSchemaHoldsValues(): void
    {
        $s = new Schema(
            id: 1,
            entryTypeHandle: 'article',
            schemaType: 'Article',
            mapping: ['headline' => '{title}'],
            sortOrder: 0,
            enabled: true,
        );

        $this->assertSame('article', $s->entryTypeHandle);
        $this->assertSame('Article', $s->schemaType);
        $this->assertSame(['headline' => '{title}'], $s->mapping);
        $this->assertTrue($s->enabled);
    }

    public function testSettingsHoldsGeoDefaults(): void
    {
        $s = new Settings(
            titleTemplate: '{title} | {siteName}',
            organizationName: 'Anvil Dev',
            organizationLogoAssetId: 42,
            socialProfiles: ['twitter' => 'https://x.com/anvil'],
        );

        $this->assertSame('{title} | {siteName}', $s->titleTemplate);
        $this->assertSame('Anvil Dev', $s->organizationName);
        $this->assertSame(42, $s->organizationLogoAssetId);
    }

    public function testSettingsToGeoDefaultsShape(): void
    {
        $s = new Settings(
            titleTemplate: '{title} | {siteName}',
            organizationName: 'Anvil Dev',
            socialProfiles: ['twitter' => 'https://x.com/anvil'],
        );
        $geo = $s->toGeoDefaults();

        $this->assertSame('{title} | {siteName}', $geo['titleTemplate']);
        $this->assertSame('Anvil Dev', $geo['organization']['name']);
        $this->assertSame(
            ['https://x.com/anvil'],
            $geo['organization']['sameAs'],
        );
        // twitter:site handle is derived from the social URL, no separate field.
        $this->assertSame('anvil', $geo['defaultTwitterSite']);
    }

    public function testSettingsDefaults(): void
    {
        $s = new Settings();
        $this->assertSame('{title}', $s->titleTemplate);
        $this->assertNull($s->organizationName);
        $this->assertSame([], $s->socialProfiles);
    }
}
