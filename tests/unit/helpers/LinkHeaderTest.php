<?php

namespace anvildev\beacon\tests\unit\helpers;

use anvildev\beacon\helpers\LinkHeader;
use PHPUnit\Framework\TestCase;
use yii\web\Response;

class LinkHeaderTest extends TestCase
{
    public function testCombinesMultipleRelationsIntoOneHeader(): void
    {
        $response = new Response();
        LinkHeader::apply($response, [
            ['url' => 'https://example.com/post', 'rel' => 'canonical'],
            ['url' => 'https://example.com/post.md', 'rel' => 'alternate', 'type' => 'text/markdown'],
            ['url' => 'https://example.com/llms.txt', 'rel' => 'describedby'],
        ]);

        $this->assertSame(
            '<https://example.com/post>; rel="canonical", '
            . '<https://example.com/post.md>; rel="alternate"; type="text/markdown", '
            . '<https://example.com/llms.txt>; rel="describedby"',
            $response->headers->get('Link'),
        );
    }

    public function testSkipsBlankUrls(): void
    {
        $response = new Response();
        LinkHeader::apply($response, [
            ['url' => '', 'rel' => 'canonical'],
            ['url' => 'https://example.com/llms.txt', 'rel' => 'describedby'],
        ]);

        $this->assertSame('<https://example.com/llms.txt>; rel="describedby"', $response->headers->get('Link'));
    }

    public function testSetsNoHeaderWhenEveryUrlIsBlank(): void
    {
        $response = new Response();
        LinkHeader::apply($response, [['url' => '', 'rel' => 'canonical']]);

        $this->assertNull($response->headers->get('Link'));
    }

    public function testStripsCrLfAndNulFromUrlsToPreventHeaderInjection(): void
    {
        $response = new Response();
        LinkHeader::apply($response, [
            ['url' => "https://example.com/post\r\nX-Injected: evil\0", 'rel' => 'canonical'],
        ]);

        $this->assertSame(
            '<https://example.com/postX-Injected: evil>; rel="canonical"',
            $response->headers->get('Link'),
        );
    }
}
