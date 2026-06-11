<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\services\AiBotsService;
use PHPUnit\Framework\TestCase;

class AiBotsServiceTest extends TestCase
{
    public function testResetDefaultsReadsFromConstant(): void
    {
        
        $this->assertIsArray(AiBotsService::DEFAULT_BOTS);
        $this->assertCount(12, AiBotsService::DEFAULT_BOTS);

        foreach (AiBotsService::DEFAULT_BOTS as $bot) {
            $this->assertArrayHasKey('name', $bot);
            $this->assertArrayHasKey('userAgentPattern', $bot);
            $this->assertNotSame('', $bot['name']);
            $this->assertNotSame('', $bot['userAgentPattern']);
        }
    }
}
