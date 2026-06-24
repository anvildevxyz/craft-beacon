<?php

namespace anvildev\beacon\tests\unit\helpers;

use anvildev\beacon\helpers\EntitySchema;
use PHPUnit\Framework\TestCase;

class EntitySchemaTest extends TestCase
{
    public function testSanitizeDropsRowsWithoutLabel(): void
    {
        $rows = EntitySchema::sanitize([
            ['label' => '', 'wikidataUrl' => 'https://www.wikidata.org/wiki/Q1'],
            ['label' => 'Keep', 'wikidataUrl' => 'https://www.wikidata.org/wiki/Q2'],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('Keep', $rows[0]['label']);
    }

    public function testSanitizeDropsRowsWithoutAnyUrl(): void
    {
        $rows = EntitySchema::sanitize([
            ['label' => 'No links', 'qid' => 'Q1'],
        ]);

        $this->assertSame([], $rows);
    }

    public function testSanitizeClampsUnknownRoleToAbout(): void
    {
        $rows = EntitySchema::sanitize([
            ['label' => 'X', 'officialUrl' => 'https://x.example', 'role' => 'bogus'],
        ]);

        $this->assertSame('about', $rows[0]['role']);
    }

    public function testSanitizePreservesMentionsRole(): void
    {
        $rows = EntitySchema::sanitize([
            ['label' => 'X', 'officialUrl' => 'https://x.example', 'role' => 'mentions'],
        ]);

        $this->assertSame('mentions', $rows[0]['role']);
    }

    public function testNodesForSplitsAboutAndMentionsWithSameAs(): void
    {
        $nodes = EntitySchema::nodesFor([
            [
                'qid' => 'Q7186', 'label' => 'Marie Curie', 'role' => 'about',
                'wikidataUrl' => 'https://www.wikidata.org/wiki/Q7186',
                'wikipediaUrl' => 'https://en.wikipedia.org/wiki/Marie_Curie',
                'officialUrl' => '',
            ],
            [
                'qid' => 'Q42', 'label' => 'Douglas Adams', 'role' => 'mentions',
                'wikidataUrl' => 'https://www.wikidata.org/wiki/Q42',
                'wikipediaUrl' => '', 'officialUrl' => '',
            ],
        ]);

        $this->assertArrayHasKey('about', $nodes);
        $this->assertArrayHasKey('mentions', $nodes);
        $this->assertCount(1, $nodes['about']);
        $this->assertSame('Thing', $nodes['about'][0]['@type']);
        $this->assertSame('Marie Curie', $nodes['about'][0]['name']);
        $this->assertSame('https://www.wikidata.org/wiki/Q7186', $nodes['about'][0]['@id']);
        $this->assertSame([
            'https://www.wikidata.org/wiki/Q7186',
            'https://en.wikipedia.org/wiki/Marie_Curie',
        ], $nodes['about'][0]['sameAs']);
        $this->assertSame('Douglas Adams', $nodes['mentions'][0]['name']);
    }

    public function testNodesForManualUrlEntityUsesUrlAsId(): void
    {
        $nodes = EntitySchema::nodesFor([
            ['label' => 'Acme', 'officialUrl' => 'https://acme.example', 'role' => 'about'],
        ]);

        $this->assertSame('https://acme.example', $nodes['about'][0]['@id']);
        $this->assertSame(['https://acme.example'], $nodes['about'][0]['sameAs']);
    }

    public function testNodesForEmptyWhenNoEntities(): void
    {
        $this->assertSame([], EntitySchema::nodesFor(null));
        $this->assertSame([], EntitySchema::nodesFor([]));
        $this->assertSame([], EntitySchema::nodesFor('not an array'));
    }
}
