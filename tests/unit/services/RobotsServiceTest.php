<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\services\RobotsService;
use PHPUnit\Framework\TestCase;

class RobotsServiceTest extends TestCase
{
    public function testRendersBaseRulesAndSitemap(): void
    {
        $service = new RobotsService();
        $output = $service->render(
            baseUserAgentRules: [
                ['userAgent' => '*', 'disallow' => ['/admin/'], 'allow' => []],
            ],
            aiCrawlerRules: [],
            sitemapUrl: 'https://example.com/sitemap.xml',
        );

        $this->assertStringContainsString("User-agent: *\n", $output);
        $this->assertStringContainsString("Disallow: /admin/\n", $output);
        $this->assertStringContainsString("Sitemap: https://example.com/sitemap.xml\n", $output);
    }

    public function testRendersAiCrawlerRules(): void
    {
        $service = new RobotsService();
        $output = $service->render(
            baseUserAgentRules: [],
            aiCrawlerRules: [
                ['bot' => 'GPTBot', 'allow' => ['/blog/'], 'disallow' => ['/admin/']],
                ['bot' => 'ClaudeBot', 'disallow' => ['/private/']],
            ],
            sitemapUrl: null,
        );

        $this->assertStringContainsString("User-agent: GPTBot\n", $output);
        $this->assertStringContainsString("Allow: /blog/\n", $output);
        $this->assertStringContainsString("Disallow: /admin/\n", $output);
        $this->assertStringContainsString("User-agent: ClaudeBot\n", $output);
        $this->assertStringContainsString("Disallow: /private/\n", $output);
    }

    public function testRendersAllowBeforeDisallowWithinBlock(): void
    {
        $service = new RobotsService();
        $output = $service->render(
            baseUserAgentRules: [],
            aiCrawlerRules: [
                ['bot' => 'GPTBot', 'allow' => ['/a'], 'disallow' => ['/b']],
            ],
            sitemapUrl: null,
        );

        $allowPos = strpos($output, 'Allow: /a');
        $disallowPos = strpos($output, 'Disallow: /b');
        $this->assertNotFalse($allowPos);
        $this->assertNotFalse($disallowPos);
        $this->assertLessThan($disallowPos, $allowPos);
    }

    public function testNoSitemapLineWhenUrlNull(): void
    {
        $service = new RobotsService();
        $output = $service->render([], [], null);
        $this->assertStringNotContainsString('Sitemap:', $output);
    }

    public function testRendersContentSignalLines(): void
    {
        $service = new RobotsService();
        $output = $service->render(
            baseUserAgentRules: [],
            aiCrawlerRules: [],
            sitemapUrl: null,
            contentSignalLines: ['# AI content-usage signals', 'User-agent: *', 'Content-Signal: ai-train=no'],
        );

        $this->assertStringContainsString("Content-Signal: ai-train=no\n", $output);
        $this->assertStringContainsString('# AI content-usage signals', $output);
    }

    public function testNoContentSignalBlockWhenEmpty(): void
    {
        $service = new RobotsService();
        $output = $service->render([], [], null, []);
        $this->assertStringNotContainsString('Content-Signal:', $output);
    }
}
