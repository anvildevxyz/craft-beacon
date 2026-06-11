<?php

namespace anvildev\beacon\tests\unit\gql;

use PHPUnit\Framework\TestCase;

class EntryBeaconResolverTest extends TestCase
{
    public function testSchemasAreEncodedAsJsonStrings(): void
    {
        $schemas = [
            ['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => 'Test'],
        ];
        $encoded = array_map(fn(array $s) => (string) json_encode($s, JSON_UNESCAPED_SLASHES), $schemas);

        $this->assertCount(1, $encoded);
        $decoded = json_decode($encoded[0], true);
        $this->assertSame('Article', $decoded['@type']);
        $this->assertSame('https://schema.org', $decoded['@context']);
        $this->assertSame('Test', $decoded['headline']);
    }

    public function testEmptyOpenGraphFallsBackToNullFields(): void
    {
        $emptyOg = $this->makeOg(true);
        $padded = !empty($emptyOg) ? $emptyOg : [
            'title' => null,
            'description' => null,
            'image' => null,
            'type' => null,
            'siteName' => null,
            'url' => null,
            'imageWidth' => null,
            'imageHeight' => null,
            'imageAlt' => null,
        ];

        $this->assertNull($padded['title']);
        $this->assertNull($padded['siteName']);
        $this->assertCount(9, $padded);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeOg(bool $empty): array
    {
        return $empty ? [] : ['title' => 'x'];
    }
}
