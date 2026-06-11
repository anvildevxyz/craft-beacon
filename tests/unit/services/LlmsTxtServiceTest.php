<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\services\LlmsTxtService;
use PHPUnit\Framework\TestCase;

class LlmsTxtServiceTest extends TestCase
{
    public function testRendersHeaderAndSummary(): void
    {
        $service = new LlmsTxtService();
        $output = $service->render('Whisper Site', 'Site description.', []);
        $this->assertStringContainsString("# Whisper Site\n", $output);
        $this->assertStringContainsString('> Site description.', $output);
    }

    public function testRendersSectionsWithEntries(): void
    {
        $service = new LlmsTxtService();
        $sections = [
            'news' => [
                ['title' => 'Welcome', 'url' => 'https://example.com/news/welcome', 'description' => 'First post'],
                ['title' => 'Update', 'url' => 'https://example.com/news/update', 'description' => null],
            ],
        ];
        $output = $service->render('Site', null, $sections);

        $this->assertStringContainsString("## news\n", $output);
        $this->assertStringContainsString('- [Welcome](https://example.com/news/welcome): First post', $output);
        $this->assertStringContainsString('- [Update](https://example.com/news/update)', $output);
        $this->assertStringNotContainsString('- [Update](https://example.com/news/update):', $output);
    }

    public function testOmitsBlockquoteWhenSummaryIsEmpty(): void
    {
        $service = new LlmsTxtService();
        $output = $service->render('Site', null, []);
        $this->assertStringNotContainsString('> ', $output);
    }

    public function testAppendsTrustBlockWhenProvided(): void
    {
        $service = new LlmsTxtService();
        $trust = ['policyUrl' => 'https://example.com/policy', 'contactEmail' => 'hi@example.com'];
        $output = $service->render('Site', null, [], $trust);
        $this->assertStringContainsString('## Trust', $output);
        $this->assertStringContainsString('Site policy URL: <https://example.com/policy>', $output);
        $this->assertStringContainsString('Contact: <hi@example.com>', $output);
    }
}
