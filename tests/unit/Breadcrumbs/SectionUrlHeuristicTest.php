<?php

namespace anvildev\beacon\tests\unit\Breadcrumbs;

use anvildev\beacon\services\BreadcrumbService;
use PHPUnit\Framework\TestCase;

final class SectionUrlHeuristicTest extends TestCase
{
    /**
     * @dataProvider uriFormatCases
     */
    public function testDeriveSectionPath(string $uriFormat, string $expected): void
    {
        $this->assertSame($expected, BreadcrumbService::deriveSectionPath($uriFormat));
    }

    /** @return array<int, array{0:string, 1:string}> */
    public static function uriFormatCases(): array
    {
        return [
            ['blog/{slug}', '/blog'],
            ['news/{year}/{slug}', '/news'],
            ['{slug}', ''],
            ['categories/{slug}/posts/{slug2}', '/categories'],
            ['', ''],
            ['plain', '/plain'],
            ['/leading/{slug}', '/leading'],
            ['trailing/{slug}/', '/trailing'],
        ];
    }
}
