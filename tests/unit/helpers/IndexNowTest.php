<?php

namespace anvildev\beacon\tests\unit\helpers;

use anvildev\beacon\helpers\IndexNow;
use PHPUnit\Framework\TestCase;

class IndexNowTest extends TestCase
{
    public function testNormalizeUrlsFiltersDedupesAndReindexes(): void
    {
        $this->assertSame(
            ['https://a.test', 'https://b.test'],
            IndexNow::normalizeUrls(['https://a.test', '', 'https://a.test', 42, 'https://b.test']),
        );
    }

    public function testBuildPayloadShapesKeyLocation(): void
    {
        $payload = IndexNow::buildPayload('example.test', 'abc123', 'https://example.test/', [
            'https://example.test/page',
        ]);

        $this->assertSame([
            'host' => 'example.test',
            'key' => 'abc123',
            'keyLocation' => 'https://example.test/abc123.txt',
            'urlList' => ['https://example.test/page'],
        ], $payload);
    }

    /**
     * @dataProvider successStatuses
     */
    public function testIsSuccessStatus(int $status, bool $expected): void
    {
        $this->assertSame($expected, IndexNow::isSuccessStatus($status));
    }

    /** @return array<string, array{0: int, 1: bool}> */
    public static function successStatuses(): array
    {
        /** @var array<string, array{0: int, 1: bool}> $cases */
        $cases = [
            '200' => [0 => 200, 1 => true],
            '204' => [0 => 204, 1 => true],
            '299' => [0 => 299, 1 => true],
            '199' => [0 => 199, 1 => false],
            '300' => [0 => 300, 1 => false],
            '422' => [0 => 422, 1 => false],
        ];

        return $cases;
    }

    public function testRejectionNoteTruncatesBody(): void
    {
        $body = str_repeat('x', 300);
        $note = IndexNow::rejectionNote($body, 50);
        $this->assertStringStartsWith('rejected — body=', $note);
        $this->assertSame(50, strlen(substr($note, strlen('rejected — body='))));
    }
}
