<?php

namespace anvildev\beacon\tests\unit\services\entities;

use anvildev\beacon\services\entities\WikidataResultParser;
use PHPUnit\Framework\TestCase;

class WikidataResultParserTest extends TestCase
{
    public function testParsesSearchHitsWithSitelinkAndOfficialUrl(): void
    {
        $search = ['search' => [
            ['id' => 'Q7186', 'label' => 'Marie Curie', 'description' => 'Polish-French physicist'],
        ]];
        $entities = ['entities' => ['Q7186' => [
            'sitelinks' => ['enwiki' => ['url' => 'https://en.wikipedia.org/wiki/Marie_Curie']],
            'claims' => ['P856' => [
                ['mainsnak' => ['datavalue' => ['value' => 'https://www.curie.fr/']]],
            ]],
        ]]];

        $rows = WikidataResultParser::parse($search, $entities, 'en');

        $this->assertCount(1, $rows);
        $this->assertSame('Q7186', $rows[0]['qid']);
        $this->assertSame('Marie Curie', $rows[0]['label']);
        $this->assertSame('https://www.wikidata.org/wiki/Q7186', $rows[0]['wikidataUrl']);
        $this->assertSame('https://en.wikipedia.org/wiki/Marie_Curie', $rows[0]['wikipediaUrl']);
        $this->assertSame('https://www.curie.fr/', $rows[0]['officialUrl']);
    }

    public function testDegradesGracefullyWhenDetailFieldsMissing(): void
    {
        $search = ['search' => [['id' => 'Q42', 'label' => 'Douglas Adams']]];

        // No entities payload at all (wbgetentities failed / empty).
        $rows = WikidataResultParser::parse($search, [], 'en');

        $this->assertCount(1, $rows);
        $this->assertSame('https://www.wikidata.org/wiki/Q42', $rows[0]['wikidataUrl']);
        $this->assertSame('', $rows[0]['wikipediaUrl']);
        $this->assertSame('', $rows[0]['officialUrl']);
        $this->assertSame('', $rows[0]['description']);
    }

    public function testUsesRequestedLanguageSitelink(): void
    {
        $search = ['search' => [['id' => 'Q64', 'label' => 'Berlin']]];
        $entities = ['entities' => ['Q64' => [
            'sitelinks' => [
                'enwiki' => ['url' => 'https://en.wikipedia.org/wiki/Berlin'],
                'dewiki' => ['url' => 'https://de.wikipedia.org/wiki/Berlin'],
            ],
        ]]];

        $rows = WikidataResultParser::parse($search, $entities, 'de');

        $this->assertSame('https://de.wikipedia.org/wiki/Berlin', $rows[0]['wikipediaUrl']);
    }

    public function testSkipsHitsWithoutQidAndFallsBackLabelToQid(): void
    {
        $search = ['search' => [
            ['label' => 'no id here'],
            ['id' => 'Q1'],
        ]];

        $rows = WikidataResultParser::parse($search, [], 'en');

        $this->assertCount(1, $rows);
        $this->assertSame('Q1', $rows[0]['qid']);
        $this->assertSame('Q1', $rows[0]['label']);
    }

    public function testReturnsEmptyWhenSearchKeyMissing(): void
    {
        $this->assertSame([], WikidataResultParser::parse([], [], 'en'));
        $this->assertSame([], WikidataResultParser::parse(['search' => 'nope'], [], 'en'));
    }

    public function testOfficialUrlSurvivesMalformedClaim(): void
    {
        $search = ['search' => [['id' => 'Q1', 'label' => 'Thing']]];
        // A malformed P856 claim list from the external API must not raise a
        // TypeError on offset access — the parser skips bad rows and keeps going.
        $entities = ['entities' => ['Q1' => ['claims' => ['P856' => [
            'not-an-array',                                              // scalar claim
            ['mainsnak' => 'string-not-array'],                         // string mainsnak
            ['mainsnak' => ['datavalue' => 'string-not-array']],        // string datavalue
            ['mainsnak' => ['datavalue' => ['value' => 'https://ok.example/']]], // valid
        ]]]]];

        $rows = WikidataResultParser::parse($search, $entities, 'en');

        $this->assertCount(1, $rows);
        $this->assertSame('https://ok.example/', $rows[0]['officialUrl']);
    }
}
