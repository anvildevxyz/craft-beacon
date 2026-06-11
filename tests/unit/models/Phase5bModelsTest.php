<?php

namespace anvildev\beacon\tests\unit\models;

use anvildev\beacon\models\AiBot;
use anvildev\beacon\models\AiCrawlerRule;
use anvildev\beacon\models\LlmsSettings;
use anvildev\beacon\models\RobotsSettings;
use anvildev\beacon\models\SitemapSettings;
use PHPUnit\Framework\TestCase;

class Phase5bModelsTest extends TestCase
{
    public function testSitemapSettings(): void
    {
        $s = new SitemapSettings(
            siteId: 1,
            sections: ['news', 'pages'],
            excludeSections: [],
            priority: 0.8,
            changefreq: 'weekly',
            newsSections: [],
            sectionSitemap: [
                'news' => ['priority' => 1.6, 'changefreq' => 'daily'],
            ],
        );
        $this->assertSame(['news', 'pages'], $s->sections);
        $this->assertSame(0.8, $s->priority);
        $resolved = $s->resolveForSection('news');
        $this->assertSame(1.0, $resolved['priority']);
        $this->assertSame('daily', $resolved['changefreq']);
        $fallback = $s->resolveForSection('pages');
        $this->assertSame(0.8, $fallback['priority']);
        $this->assertSame('weekly', $fallback['changefreq']);
    }

    public function testLlmsSettings(): void
    {
        $s = new LlmsSettings(
            siteId: 1,
            enabled: true,
            summary: 'Test',
            siteNameOverride: null,
            sections: ['news'],
        );
        $this->assertTrue($s->enabled);
        $this->assertSame('Test', $s->summary);
    }

    public function testRobotsSettings(): void
    {
        $rules = [['userAgent' => '*', 'disallow' => ['/admin/']]];
        $s = new RobotsSettings(siteId: 1, sitemapUrl: 'auto', userAgentRules: $rules);
        $this->assertSame('auto', $s->sitemapUrl);
        $this->assertCount(1, $s->userAgentRules);
    }

    public function testAiCrawlerRule(): void
    {
        $r = new AiCrawlerRule(
            id: 1,
            botName: 'GPTBot',
            allowPaths: ['/blog/'],
            disallowPaths: ['/admin/'],
            sortOrder: 0,
            enabled: true,
        );
        $this->assertSame('GPTBot', $r->botName);
        $this->assertSame(['/blog/'], $r->allowPaths);
    }

    public function testAiBot(): void
    {
        $b = new AiBot(
            id: 1,
            name: 'GPTBot',
            userAgentPattern: 'GPTBot/.*',
            enabled: true,
            source: 'default',
            sortOrder: 0,
        );
        $this->assertSame('GPTBot', $b->name);
        $this->assertSame('default', $b->source);
    }
}
