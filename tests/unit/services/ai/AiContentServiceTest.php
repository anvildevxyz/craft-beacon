<?php

namespace anvildev\beacon\tests\unit\services\ai;

use anvildev\beacon\services\AiContentService;
use PHPUnit\Framework\TestCase;

class AiContentServiceTest extends TestCase
{
    public function testFaqSchemaBuildsValidFaqPageNode(): void
    {
        $service = new AiContentService();
        $schema = $service->faqSchema([
            ['question' => 'What is GEO?', 'answer' => 'Generative Engine Optimization.'],
            ['question' => 'Why?', 'answer' => 'AI visibility.'],
        ]);

        $this->assertSame('https://schema.org', $schema['@context']);
        $this->assertSame('FAQPage', $schema['@type']);
        $this->assertCount(2, $schema['mainEntity']);
        $this->assertSame('Question', $schema['mainEntity'][0]['@type']);
        $this->assertSame('What is GEO?', $schema['mainEntity'][0]['name']);
        $this->assertSame('Answer', $schema['mainEntity'][0]['acceptedAnswer']['@type']);
        $this->assertSame('Generative Engine Optimization.', $schema['mainEntity'][0]['acceptedAnswer']['text']);
    }

    public function testFaqSchemaHandlesEmptyInput(): void
    {
        $schema = (new AiContentService())->faqSchema([]);
        $this->assertSame('FAQPage', $schema['@type']);
        $this->assertSame([], $schema['mainEntity']);
    }
}
