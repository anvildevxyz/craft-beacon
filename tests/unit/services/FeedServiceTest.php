<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\services\FeedService;
use craft\elements\Entry;
use DateTime;
use PHPUnit\Framework\TestCase;

class FeedServiceTest extends TestCase
{
    public function testRenderJsonFeedProducesVersionedItems(): void
    {
        $service = new FeedService();
        $entries = [
            $this->mockEntry('Post A', 'https://example.test/blog/post-a', '2026-05-01T10:00:00+00:00', '2026-05-02T11:00:00+00:00'),
            $this->mockEntry('Post B', 'https://example.test/blog/post-b', '2026-05-03T10:00:00+00:00', '2026-05-04T11:00:00+00:00'),
        ];

        $json = $service->renderJsonFeed(
            'Example Site',
            'https://example.test',
            'https://example.test/feed/blog.json',
            'blog',
            $entries,
        );

        $this->assertStringContainsString('"version": "https://jsonfeed.org/version/1.1"', $json);
        $this->assertStringContainsString('"feed_url": "https://example.test/feed/blog.json"', $json);
        $this->assertStringContainsString('"title": "Post A"', $json);
        $this->assertStringContainsString('"title": "Post B"', $json);
    }

    public function testRenderAtomFeedIncludesUpdatedAndEntries(): void
    {
        $service = new FeedService();
        $entries = [
            $this->mockEntry('Post A', 'https://example.test/blog/post-a', '2026-05-01T10:00:00+00:00', '2026-05-02T11:00:00+00:00'),
            $this->mockEntry('Post B', 'https://example.test/blog/post-b', '2026-05-03T10:00:00+00:00', '2026-05-04T11:00:00+00:00'),
        ];

        $xml = $service->renderAtomFeed(
            'Example Site',
            'https://example.test',
            'https://example.test/feed/blog.atom',
            'blog',
            $entries,
        );

        $this->assertStringContainsString('<feed xmlns="http://www.w3.org/2005/Atom">', $xml);
        $this->assertStringContainsString('<id>https://example.test/feed/blog.atom</id>', $xml);
        $this->assertStringContainsString('<title>Post A</title>', $xml);
        $this->assertStringContainsString('<title>Post B</title>', $xml);
        $this->assertStringContainsString('<updated>2026-05-04T11:00:00+00:00</updated>', $xml);
    }

    private function mockEntry(string $title, string $url, string $postDateIso, string $updatedIso): Entry
    {
        /** @var Entry&\PHPUnit\Framework\MockObject\MockObject $entry */
        $entry = $this->getMockBuilder(Entry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getUrl'])
            ->getMock();
        $entry->title = $title;
        $entry->postDate = new DateTime($postDateIso);
        $entry->dateUpdated = new DateTime($updatedIso);
        $entry->method('getUrl')->willReturn($url);

        return $entry;
    }
}

