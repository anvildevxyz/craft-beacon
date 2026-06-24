<?php

namespace anvildev\beacon\tests\unit\services\ai;

use anvildev\beacon\services\ai\AiPromptBuilder;
use PHPUnit\Framework\TestCase;

class AiPromptBuilderTest extends TestCase
{
    public function testMetaTitlePromptIncludesTitleContentAndCharBudget(): void
    {
        $p = (new AiPromptBuilder())->metaTitle('My Page', 'Some body content here.');
        $this->assertStringContainsString('SEO', $p['system']);
        $this->assertStringContainsString('My Page', $p['user']);
        $this->assertStringContainsString('Some body content here.', $p['user']);
        $this->assertStringContainsString('50', $p['user']); // 50–60 char budget
    }

    public function testDescriptionPromptStatesDescriptionBudget(): void
    {
        $p = (new AiPromptBuilder())->metaDescription('Title', 'Body');
        $this->assertStringContainsString('150', $p['user']);
    }

    public function testFaqPromptForbidsInventionAndAsksForJson(): void
    {
        $p = (new AiPromptBuilder())->faq('Title', 'Body');
        $this->assertStringContainsString('JSON', $p['system']);
        $this->assertStringContainsStringIgnoringCase('never invent', $p['system']);
    }

    public function testAltTextPromptEmbedsFilenameAndPageContext(): void
    {
        $p = (new AiPromptBuilder())->altText('sunset.jpg', 'Travel guide');
        $this->assertStringContainsString('sunset.jpg', $p['user']);
        $this->assertStringContainsString('Travel guide', $p['user']);
    }

    public function testAltTextPromptOmitsPageContextWhenAbsent(): void
    {
        $p = (new AiPromptBuilder())->altText('logo.png');
        $this->assertStringContainsString('logo.png', $p['user']);
        $this->assertStringNotContainsString('appears on a page', $p['user']);
    }

    public function testContextBlockRendersSectionScoreAndWeakPillars(): void
    {
        $p = (new AiPromptBuilder())->metaTitle('T', 'C', [
            'section' => 'Blog',
            'geoScore' => 42,
            'weakPillars' => ['factDensity', 'chunkability'],
        ]);
        $this->assertStringContainsString('Section: Blog', $p['user']);
        $this->assertStringContainsString('42/100', $p['user']);
        $this->assertStringContainsString('factDensity, chunkability', $p['user']);
    }

    public function testLongContentIsTruncatedToBudget(): void
    {
        $content = str_repeat('x', AiPromptBuilder::MAX_CONTENT_CHARS + 5000);
        $p = (new AiPromptBuilder())->summary('T', $content);
        $this->assertStringContainsString('[truncated]', $p['user']);
        // The user prompt should not carry the full oversized body verbatim.
        $this->assertLessThan(strlen($content), strlen($p['user']));
    }
}
