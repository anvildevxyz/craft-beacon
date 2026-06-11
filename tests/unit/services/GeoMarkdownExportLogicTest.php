<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\services\GeoMarkdownExportService;
use craft\elements\Entry;
use craft\models\Section;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pure-logic coverage for {@see GeoMarkdownExportService}: excerpt truncation,
 * section-handle extraction, the section allowlist gate, and per-element front
 * matter. These avoid the template-render and DB paths.
 */
class GeoMarkdownExportLogicTest extends TestCase
{
    public function testTruncateBodyLeavesShortBodyUntouched(): void
    {
        $service = $this->service();
        $this->assertSame('Short body.', $this->invoke($service, 'truncateBody', ['  Short body.  ', 100]));
        $this->assertSame('', $this->invoke($service, 'truncateBody', ['   ', 100]));
    }

    public function testTruncateBodyBreaksOnWordBoundaryAndAppendsEllipsis(): void
    {
        $service = $this->service();
        $body = 'The quick brown fox jumps over the lazy dog and keeps running onward';
        $result = $this->invoke($service, 'truncateBody', [$body, 24]);

        $this->assertStringEndsWith('...', $result);
        // Cut should land on a word boundary (no partial trailing word).
        $this->assertStringNotContainsString('ju...', $result);
        $this->assertLessThanOrEqual(27, mb_strlen($result));
    }

    public function testTruncateBodyStripsTrailingPunctuationBeforeEllipsis(): void
    {
        $service = $this->service();
        // No spaces past the 60% floor, so it hard-cuts then strips punctuation.
        $result = $this->invoke($service, 'truncateBody', ['alpha,beta;gamma:delta.epsilon', 12]);
        $this->assertStringEndsWith('...', $result);
        $this->assertStringNotContainsString(',...', $result);
        $this->assertStringNotContainsString('.....', $result);
    }

    public function testSectionHandleReturnsTrimmedHandleOrEmpty(): void
    {
        $service = $this->service();
        $this->assertSame('', $this->invoke($service, 'sectionHandle', [null]));

        $section = $this->section('  news  ');
        $this->assertSame('news', $this->invoke($service, 'sectionHandle', [$section]));
    }

    public function testIsAllowedSectionTreatsEmptyAllowlistAsAll(): void
    {
        $service = $this->service();
        $entry = $this->entryWithSection($this->section('news'));
        $this->assertTrue($this->invoke($service, 'isAllowedSection', [$entry, []]));
    }

    public function testIsAllowedSectionMatchesAgainstAllowlist(): void
    {
        $service = $this->service();
        $entry = $this->entryWithSection($this->section('news'));

        $this->assertTrue($this->invoke($service, 'isAllowedSection', [$entry, ['news', 'blog']]));
        $this->assertFalse($this->invoke($service, 'isAllowedSection', [$entry, ['blog']]));
    }

    public function testIsAllowedSectionRejectsEntryWithoutSectionHandle(): void
    {
        $service = $this->service();
        $entry = $this->entryWithSection(null);
        $this->assertFalse($this->invoke($service, 'isAllowedSection', [$entry, ['news']]));
    }

    public function testElementFrontMatterBuildsAndFiltersEmptyValues(): void
    {
        $service = $this->service();

        $entry = $this->createMock(Entry::class);
        $entry->method('getUrl')->willReturn('https://example.test/post');
        $entry->title = 'Post Title';
        $entry->dateUpdated = new \DateTime('2026-05-01T10:00:00+00:00');

        $fm = $this->invoke($service, 'elementFrontMatter', [$entry]);

        $this->assertSame('Post Title', $fm['title']);
        $this->assertSame('https://example.test/post', $fm['canonical']);
        $this->assertSame('2026-05-01T10:00:00+00:00', $fm['lastUpdated']);
    }

    public function testElementFrontMatterDropsMissingUrl(): void
    {
        $service = $this->service();

        $entry = $this->createMock(Entry::class);
        $entry->method('getUrl')->willReturn(null);
        $entry->title = 'No URL';
        $entry->dateUpdated = null;

        $fm = $this->invoke($service, 'elementFrontMatter', [$entry]);

        $this->assertSame('No URL', $fm['title']);
        $this->assertArrayNotHasKey('canonical', $fm);
        $this->assertArrayNotHasKey('lastUpdated', $fm);
    }

    private function service(): GeoMarkdownExportService
    {
        return (new ReflectionClass(GeoMarkdownExportService::class))->newInstanceWithoutConstructor();
    }

    private function section(string $handle): Section
    {
        $section = (new ReflectionClass(Section::class))->newInstanceWithoutConstructor();
        $section->handle = $handle;
        return $section;
    }

    private function entryWithSection(?Section $section): Entry
    {
        $entry = $this->createMock(Entry::class);
        $entry->method('getSection')->willReturn($section);
        return $entry;
    }

    /**
     * @param array<int,mixed> $args
     */
    private function invoke(object $obj, string $method, array $args): mixed
    {
        $ref = new ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }
}
