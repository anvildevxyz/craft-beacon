<?php

namespace anvildev\beacon\tests\unit\models;

use anvildev\beacon\models\BotDefinition;
use anvildev\beacon\models\RenderedOutput;
use DateTime;
use PHPUnit\Framework\TestCase;

class ModelTest extends TestCase
{
    public function testRenderedOutputHoldsContent(): void
    {
        $out = new RenderedOutput('xml-content', new DateTime('2026-04-30'));
        $this->assertSame('xml-content', $out->content);
        $this->assertSame('2026-04-30', $out->generatedAt->format('Y-m-d'));
    }

    public function testBotDefinitionMatchesUserAgent(): void
    {
        $bot = new BotDefinition('GPTBot', 'GPTBot/.*');
        $this->assertTrue($bot->matches('Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)'));
        $this->assertFalse($bot->matches('Mozilla/5.0 Firefox'));
    }

    public function testBotDefinitionRequiresValidName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BotDefinition('', 'pattern');
    }
}
