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
