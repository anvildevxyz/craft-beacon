<?php

namespace anvildev\beacon\tests\unit\gql;

use anvildev\beacon\gql\types\SeoFieldAiMarkdownType;
use anvildev\beacon\gql\types\SeoFieldEntityType;
use anvildev\beacon\gql\types\SeoFieldRobotsType;
use anvildev\beacon\gql\types\SeoFieldSchemaAddonType;
use anvildev\beacon\gql\types\SeoFieldValueType;
use PHPUnit\Framework\TestCase;

/**
 * Field-definition + resolver coverage for the SEO field's GraphQL content
 * type (issue #38: querying a Beacon SEO field died with "String cannot
 * represent value" because the base Field typed the value as String).
 *
 * Resolver closures are exercised directly against normalized-value arrays;
 * schema-shape execution lives in the integration suite.
 */
class SeoFieldGqlTypeTest extends TestCase
{
    public function testTypeNamesAreStable(): void
    {
        $this->assertSame('BeaconSeoFieldValue', SeoFieldValueType::getName());
        $this->assertSame('BeaconSeoFieldRobots', SeoFieldRobotsType::getName());
        $this->assertSame('BeaconSeoFieldSchemaAddon', SeoFieldSchemaAddonType::getName());
        $this->assertSame('BeaconSeoFieldEntity', SeoFieldEntityType::getName());
        $this->assertSame('BeaconSeoFieldAiMarkdown', SeoFieldAiMarkdownType::getName());
    }

    public function testValueTypeCoversEveryNormalizedValueKey(): void
    {
        $this->skipWithoutCraft();

        $fields = SeoFieldValueType::getFieldDefinitions();
        foreach (['title', 'description', 'ogImageId', 'canonical', 'robots', 'aiUsage', 'schemaAddons', 'authorIds', 'entities', 'aiMarkdown'] as $name) {
            $this->assertArrayHasKey($name, $fields, "Missing field: $name");
        }
    }

    public function testRobotsTypeMapsStoredKeysToGraphqlNames(): void
    {
        $fields = SeoFieldRobotsType::getFieldDefinitions();
        foreach (['noindex', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex', 'notranslate', 'indexifembedded', 'maxSnippet', 'maxImagePreview', 'maxVideoPreview', 'unavailableAfter'] as $name) {
            $this->assertArrayHasKey($name, $fields, "Missing field: $name");
        }

        $stored = [
            'noindex' => true,
            'notranslate' => '1',
            'max-snippet' => '160',
            'max-image-preview' => 'large',
            'unavailable_after' => '2026-12-31T23:59:59Z',
        ];

        $this->assertTrue($this->resolver($fields, 'noindex')($stored));
        $this->assertTrue($this->resolver($fields, 'notranslate')($stored));
        // Missing key (legacy data predating a directive) resolves to false.
        $this->assertFalse($this->resolver($fields, 'nofollow')($stored));
        $this->assertSame('160', $this->resolver($fields, 'maxSnippet')($stored));
        $this->assertSame('large', $this->resolver($fields, 'maxImagePreview')($stored));
        $this->assertSame('2026-12-31T23:59:59Z', $this->resolver($fields, 'unavailableAfter')($stored));
        // Unset value-typed directive ('' or missing) resolves to null.
        $this->assertNull($this->resolver($fields, 'maxVideoPreview')($stored + ['max-video-preview' => '']));
    }

    public function testAiMarkdownResolversDefaultDefensively(): void
    {
        $fields = SeoFieldAiMarkdownType::getFieldDefinitions();

        $this->assertSame('exclude', $this->resolver($fields, 'enabled')(['enabled' => 'exclude']));
        $this->assertSame('inherit', $this->resolver($fields, 'enabled')(['enabled' => 'bogus']));
        $this->assertSame('inherit', $this->resolver($fields, 'enabled')([]));
        $this->assertSame('', $this->resolver($fields, 'customFrontMatter')([]));
        $this->assertSame("foo: bar\n", $this->resolver($fields, 'customFrontMatter')(['customFrontMatter' => "foo: bar\n"]));
    }

    public function testSchemaAddonMappingIsJsonEncoded(): void
    {
        $fields = SeoFieldSchemaAddonType::getFieldDefinitions();

        $this->assertSame('FAQPage', $this->resolver($fields, 'type')(['type' => 'FAQPage']));
        $this->assertNull($this->resolver($fields, 'type')([]));

        $mapping = $this->resolver($fields, 'mapping')(['mapping' => ['name' => 'title']]);
        $this->assertSame(['name' => 'title'], json_decode($mapping, true));
        $this->assertSame([], json_decode($this->resolver($fields, 'mapping')([]), true));
    }

    public function testValueTypeResolversToleratePartialLegacyData(): void
    {
        $this->skipWithoutCraft();

        $fields = SeoFieldValueType::getFieldDefinitions();

        // Explicit nulls (pre-normalize legacy rows) must not bubble into
        // non-null GraphQL fields.
        $legacy = ['robots' => null, 'aiMarkdown' => null, 'schemaAddons' => null, 'authorIds' => null, 'entities' => null, 'aiUsage' => null];
        $this->assertSame([], $this->resolver($fields, 'robots')($legacy));
        $this->assertSame([], $this->resolver($fields, 'aiMarkdown')($legacy));
        $this->assertSame([], $this->resolver($fields, 'schemaAddons')($legacy));
        $this->assertSame([], $this->resolver($fields, 'authorIds')($legacy));
        $this->assertSame([], $this->resolver($fields, 'entities')($legacy));
        $this->assertSame('', $this->resolver($fields, 'aiUsage')($legacy));

        $this->assertSame([3, 7], $this->resolver($fields, 'authorIds')(['authorIds' => ['3', 7, 'x']]));
    }

    /**
     * Fetches a field's resolver, asserting it exists (the optional
     * `resolve` offset keeps PHPStan honest at call sites otherwise).
     *
     * @param array<string, array{type: \GraphQL\Type\Definition\Type, description?: string, resolve?: callable}> $fields
     */
    private function resolver(array $fields, string $name): callable
    {
        $resolve = $fields[$name]['resolve'] ?? null;
        $this->assertIsCallable($resolve, "Field $name has no resolver");
        return $resolve;
    }

    private function skipWithoutCraft(): void
    {
        if (!class_exists(\Craft::class) || \Craft::$app === null) {
            $this->markTestSkipped('Requires initialized Craft (GqlEntityRegistry needs Craft::$app->getConfig()).');
        }
    }
}
