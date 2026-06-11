<?php

namespace anvildev\beacon\tests\unit\models;

use anvildev\beacon\models\BotDefinition;
use PHPUnit\Framework\TestCase;

class BotDefinitionTest extends TestCase
{
    public function testMatchesReturnsTrueForUserAgentMatchingPattern(): void
    {
        $bot = new BotDefinition('GPTBot', 'GPTBot/.*');
        $this->assertTrue($bot->matches('Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)'));
    }

    public function testMatchesReturnsFalseForUnrelatedUserAgent(): void
    {
        $bot = new BotDefinition('GPTBot', 'GPTBot/.*');
        $this->assertFalse($bot->matches('Mozilla/5.0 Firefox normal browser'));
    }

    public function testMatchesIsCaseInsensitive(): void
    {
        $bot = new BotDefinition('ClaudeBot', 'ClaudeBot/.*');
        $this->assertTrue($bot->matches('claudebot/2.0'));
    }

    public function testRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BotDefinition('', 'pattern');
    }
}
