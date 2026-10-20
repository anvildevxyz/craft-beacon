<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\enums\BotPurpose;
use anvildev\beacon\services\AiBotsService;
use PHPUnit\Framework\TestCase;

class AiBotsServiceTest extends TestCase
{
    public function testResetDefaultsReadsFromConstant(): void
    {
        $this->assertIsArray(AiBotsService::DEFAULT_BOTS);
        $this->assertCount(16, AiBotsService::DEFAULT_BOTS);

        foreach (AiBotsService::DEFAULT_BOTS as $bot) {
            $this->assertArrayHasKey('name', $bot);
            $this->assertArrayHasKey('userAgentPattern', $bot);
            $this->assertArrayHasKey('purpose', $bot);
            $this->assertNotSame('', $bot['name']);
            $this->assertNotSame('', $bot['userAgentPattern']);
            $this->assertInstanceOf(
                BotPurpose::class,
                BotPurpose::tryFrom($bot['purpose']),
                "DEFAULT_BOTS entry '{$bot['name']}' has an unrecognized purpose value: {$bot['purpose']}",
            );
        }
    }

    public function testClaudeWebIsReplacedByItsThreeSuccessorCrawlers(): void
    {
        $names = array_column(AiBotsService::DEFAULT_BOTS, 'name');

        $this->assertNotContains('Claude-Web', $names);
        $this->assertContains('Claude-User', $names);
        $this->assertContains('Claude-SearchBot', $names);
    }

    public function testNewCrawlersAreInTheDefaultList(): void
    {
        $names = array_column(AiBotsService::DEFAULT_BOTS, 'name');

        $this->assertContains('Perplexity-User', $names);
        $this->assertContains('Meta-ExternalAgent', $names);
        $this->assertContains('Meta-ExternalFetcher', $names);
    }

    public function testDefaultBotNamesAreUnique(): void
    {
        $names = array_column(AiBotsService::DEFAULT_BOTS, 'name');

        $this->assertCount(count(array_unique($names)), $names);
    }
}
