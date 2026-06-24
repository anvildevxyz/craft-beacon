<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\services\entities\WikidataClientInterface;
use anvildev\beacon\services\entities\WikidataException;
use anvildev\beacon\services\WikidataService;
use PHPUnit\Framework\TestCase;

class WikidataServiceTest extends TestCase
{
    public function testFetchReturnsClientRows(): void
    {
        $service = new WikidataService();
        $service->client = new class implements WikidataClientInterface {
            public function search(string $query, string $language, int $limit): array
            {
                return [[
                    'qid' => 'Q1',
                    'label' => 'Test',
                    'description' => '',
                    'wikidataUrl' => 'https://www.wikidata.org/wiki/Q1',
                    'wikipediaUrl' => '',
                    'officialUrl' => '',
                ]];
            }
        };

        $rows = $service->fetch('test query');

        $this->assertCount(1, $rows);
        $this->assertSame('Q1', $rows[0]['qid']);
    }

    public function testFetchDegradesToEmptyWhenClientThrows(): void
    {
        $service = new WikidataService();
        $service->client = new class implements WikidataClientInterface {
            public function search(string $query, string $language, int $limit): array
            {
                throw new WikidataException('boom');
            }
        };

        $this->assertSame([], $service->fetch('anything'));
    }

    public function testFetchSkipsShortQueriesWithoutCallingClient(): void
    {
        $service = new WikidataService();
        $service->client = new class implements WikidataClientInterface {
            public function search(string $query, string $language, int $limit): array
            {
                throw new \RuntimeException('should not be called');
            }
        };

        $this->assertSame([], $service->fetch('a'));
        $this->assertSame([], $service->fetch('  '));
    }
}
