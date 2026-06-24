<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\models\SchemaBundle;
use anvildev\beacon\schemas\SchemaTemplate;
use anvildev\beacon\services\SchemaService;
use PHPUnit\Framework\TestCase;

class SchemaServiceEntityTest extends TestCase
{
    public function testAttachesAboutAndMentionsToPrimaryNode(): void
    {
        $service = $this->serviceWithArticleTemplate();
        $bundle = new SchemaBundle();
        $bundle->schemas = [['type' => 'Article', 'mapping' => []]];

        $context = ['seo' => ['entities' => [
            [
                'qid' => 'Q7186', 'label' => 'Marie Curie', 'role' => 'about',
                'wikidataUrl' => 'https://www.wikidata.org/wiki/Q7186',
                'wikipediaUrl' => '', 'officialUrl' => '',
            ],
            [
                'qid' => 'Q42', 'label' => 'Adams', 'role' => 'mentions',
                'wikidataUrl' => 'https://www.wikidata.org/wiki/Q42',
                'wikipediaUrl' => '', 'officialUrl' => '',
            ],
        ]]];

        $output = $service->render($bundle, [], $context);

        $this->assertCount(1, $output);
        $this->assertArrayHasKey('about', $output[0]);
        $this->assertArrayHasKey('mentions', $output[0]);
        $this->assertSame('Marie Curie', $output[0]['about'][0]['name']);
        $this->assertSame('Adams', $output[0]['mentions'][0]['name']);
    }

    public function testDoesNotClobberMappingProvidedAbout(): void
    {
        $service = $this->serviceWithArticleTemplate(['about' => 'preset']);
        $bundle = new SchemaBundle();
        $bundle->schemas = [['type' => 'Article', 'mapping' => []]];

        $context = ['seo' => ['entities' => [
            ['qid' => 'Q1', 'label' => 'X', 'role' => 'about', 'wikidataUrl' => 'https://www.wikidata.org/wiki/Q1', 'wikipediaUrl' => '', 'officialUrl' => ''],
        ]]];

        $output = $service->render($bundle, [], $context);

        $this->assertSame('preset', $output[0]['about']);
    }

    public function testNoEntitiesLeavesNodeUntouched(): void
    {
        $service = $this->serviceWithArticleTemplate();
        $bundle = new SchemaBundle();
        $bundle->schemas = [['type' => 'Article', 'mapping' => []]];

        $output = $service->render($bundle, [], ['seo' => []]);

        $this->assertArrayNotHasKey('about', $output[0]);
        $this->assertArrayNotHasKey('mentions', $output[0]);
    }

    public function testEmitsWebPageHostWhenNoSchemaBundleMapped(): void
    {
        // No template factories + empty bundle => no primary node, mirroring an
        // entry type with no schema mapping.
        $service = new SchemaService([]);
        $bundle = new SchemaBundle();

        $context = [
            'title' => 'Understanding Twig Templates',
            'entry' => ['url' => 'https://example.com/twig'],
            'seo' => ['entities' => [
                [
                    'qid' => 'Q110260868', 'label' => 'Craft CMS', 'role' => 'about',
                    'wikidataUrl' => 'https://www.wikidata.org/wiki/Q110260868',
                    'wikipediaUrl' => 'https://en.wikipedia.org/wiki/Craft_CMS',
                    'officialUrl' => 'https://craftcms.com/',
                ],
            ]],
        ];

        $output = $service->render($bundle, [], $context);

        $this->assertCount(1, $output);
        $this->assertSame('WebPage', $output[0]['@type']);
        $this->assertSame('https://example.com/twig#webpage', $output[0]['@id']);
        $this->assertSame('https://example.com/twig', $output[0]['url']);
        $this->assertSame('Understanding Twig Templates', $output[0]['name']);
        $this->assertArrayHasKey('about', $output[0]);
        $this->assertSame('Craft CMS', $output[0]['about'][0]['name']);
        $this->assertSame([
            'https://www.wikidata.org/wiki/Q110260868',
            'https://en.wikipedia.org/wiki/Craft_CMS',
            'https://craftcms.com/',
        ], $output[0]['about'][0]['sameAs']);
    }

    public function testDoesNotEmitWebPageHostWhenPrimaryNodeExists(): void
    {
        $service = $this->serviceWithArticleTemplate();
        $bundle = new SchemaBundle();
        $bundle->schemas = [['type' => 'Article', 'mapping' => []]];

        $context = [
            'title' => 'T',
            'entry' => ['url' => 'https://example.com/x'],
            'seo' => ['entities' => [
                ['qid' => 'Q1', 'label' => 'X', 'role' => 'about', 'wikidataUrl' => 'https://www.wikidata.org/wiki/Q1', 'wikipediaUrl' => '', 'officialUrl' => ''],
            ]],
        ];

        $output = $service->render($bundle, [], $context);

        // Only the Article node — no extra WebPage host, and it carries about.
        $this->assertCount(1, $output);
        $this->assertSame('Article', $output[0]['@type']);
        $this->assertArrayHasKey('about', $output[0]);
    }

    public function testNoWebPageHostWhenEntitiesEmptyAndNoBundle(): void
    {
        $service = new SchemaService([]);
        $output = $service->render(new SchemaBundle(), [], ['seo' => ['entities' => []]]);
        $this->assertSame([], $output);
    }

    /**
     * @param array<string,mixed> $extra extra keys the fake template emits
     */
    private function serviceWithArticleTemplate(array $extra = []): SchemaService
    {
        $factory = fn(): SchemaTemplate => new class($extra) extends SchemaTemplate {
            /** @param array<string,mixed> $extra */
            public function __construct(private array $extra)
            {
            }

            public function render(array $context, array $mapping): array
            {
                return ['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => 'X'] + $this->extra;
            }
        };

        return new SchemaService(['Article' => $factory]);
    }
}
