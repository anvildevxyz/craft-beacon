<?php

namespace anvildev\beacon\tests\unit\schemas;

use anvildev\beacon\schemas\SchemaTemplate;
use anvildev\beacon\services\ExpressionEvaluator;
use PHPUnit\Framework\TestCase;

class AllTemplatesTest extends TestCase
{
    /** @return array<string, array{0:string}> */
    public static function templateProvider(): array
    {
        return [
            'Article' => ['Article'],
            'Product' => ['Product'],
            'Recipe' => ['Recipe'],
            'HowTo' => ['HowTo'],
            'FAQPage' => ['FAQPage'],
            'Review' => ['Review'],
        ];
    }

    /**
     * @dataProvider templateProvider
     */
    public function testTemplateRendersWithCorrectType(string $expectedType): void
    {
        $template = new SchemaTemplate(new ExpressionEvaluator(), $expectedType);
        $output = $template->render(['title' => 'X'], ['name' => '{title}']);

        $this->assertSame($expectedType, $output['@type']);
        $this->assertSame('https://schema.org', $output['@context']);
        $this->assertSame('X', $output['name']);
    }
}
