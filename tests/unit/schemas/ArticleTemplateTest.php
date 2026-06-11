<?php

namespace anvildev\beacon\tests\unit\schemas;

use anvildev\beacon\schemas\SchemaTemplate;
use anvildev\beacon\services\ExpressionEvaluator;
use PHPUnit\Framework\TestCase;

class ArticleTemplateTest extends TestCase
{
    public function testRendersArticleWithTitleAndAuthor(): void
    {
        $template = new SchemaTemplate(new ExpressionEvaluator(), 'Article');
        $context = [
            'title' => 'My Post',
            'seo' => ['description' => 'A description'],
            'authors' => [['name' => 'Jane Doe']],
        ];
        $mapping = [
            'headline' => '{title}',
            'description' => '{seo.description}',
            'author' => '{authors.0.name}',
        ];

        $output = $template->render($context, $mapping);

        $this->assertSame('Article', $output['@type']);
        $this->assertSame('My Post', $output['headline']);
        $this->assertSame('A description', $output['description']);
        $this->assertSame('Jane Doe', $output['author']);
        $this->assertSame('https://schema.org', $output['@context']);
    }

    public function testOmitsBlankFields(): void
    {
        $template = new SchemaTemplate(new ExpressionEvaluator(), 'Article');
        $output = $template->render(['title' => 'X'], ['headline' => '{title}', 'description' => '{missing}']);

        $this->assertArrayNotHasKey('description', $output);
    }
}
