<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\services\GeoMarkdownExportService;
use craft\elements\Entry;
use craft\models\Section;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class GeoMarkdownExportServicePolicyTest extends TestCase
{
    public function testTruncateBodyKeepsShortTextUnchanged(): void
    {
        $service = new GeoMarkdownExportService();
        $result = $this->invokePrivate($service, 'truncateBody', ['Short text', 120]);
        $this->assertSame('Short text', $result);
    }

    public function testTruncateBodyTrimsAtWordBoundaryAndAppendsEllipsis(): void
    {
        $service = new GeoMarkdownExportService();
        $body = 'This is a long body intended to test the word-safe truncation behavior for GEO markdown exports.';
        $result = $this->invokePrivate($service, 'truncateBody', [$body, 45]);

        $this->assertStringEndsWith('...', $result);
        $this->assertLessThanOrEqual(48, mb_strlen($result));
    }

    public function testAllowedSectionReturnsTrueWhenAllowlistEmpty(): void
    {
        $service = new GeoMarkdownExportService();
        $entry = $this->mockEntrySection('blog');

        $allowed = $this->invokePrivate($service, 'isAllowedSection', [$entry, []]);
        $this->assertTrue($allowed);
    }

    public function testAllowedSectionReturnsFalseWhenSectionNotListed(): void
    {
        $service = new GeoMarkdownExportService();
        $entry = $this->mockEntrySection('docs');

        $allowed = $this->invokePrivate($service, 'isAllowedSection', [$entry, ['blog', 'news']]);
        $this->assertFalse($allowed);
    }

    public function testAllowedSectionReturnsTrueWhenSectionIsListed(): void
    {
        $service = new GeoMarkdownExportService();
        $entry = $this->mockEntrySection('news');

        $allowed = $this->invokePrivate($service, 'isAllowedSection', [$entry, ['blog', 'news']]);
        $this->assertTrue($allowed);
    }

    private function mockEntrySection(string $sectionHandle): Entry
    {
        /** @var Entry&\PHPUnit\Framework\MockObject\MockObject $entry */
        $entry = $this->getMockBuilder(Entry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSection'])
            ->getMock();
        $entry->method('getSection')->willReturn(new Section(['handle' => $sectionHandle]));
        return $entry;
    }

    /**
     * @param list<mixed> $args
     */
    private function invokePrivate(object $target, string $method, array $args): mixed
    {
        $ref = new ReflectionClass($target);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($target, $args);
    }
}

