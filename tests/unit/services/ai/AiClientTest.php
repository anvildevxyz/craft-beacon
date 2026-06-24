<?php

namespace anvildev\beacon\tests\unit\services\ai;

use anvildev\beacon\services\AiClient;
use anvildev\beacon\services\ai\AiException;
use anvildev\beacon\services\ai\AiProviderInterface;
use PHPUnit\Framework\TestCase;

class AiClientTest extends TestCase
{
    public function testConfiguredWhenProviderInjected(): void
    {
        $client = new AiClient();
        $client->provider = $this->fakeProvider('result');
        $this->assertTrue($client->isConfigured());
    }

    public function testCompleteReturnsProviderOutput(): void
    {
        $client = new AiClient();
        $client->provider = $this->fakeProvider('the answer');
        $this->assertSame('the answer', $client->complete('system', 'user'));
    }

    public function testCompletePassesPromptsThrough(): void
    {
        $provider = new class implements AiProviderInterface {
            public string $system = '';
            public string $user = '';

            public function complete(string $system, string $user, array $options = []): string
            {
                $this->system = $system;
                $this->user = $user;
                return 'ok';
            }
        };
        $client = new AiClient();
        $client->provider = $provider;
        $client->complete('SYS', 'USR');
        $this->assertSame('SYS', $provider->system);
        $this->assertSame('USR', $provider->user);
    }

    public function testDormantWhenUnconfiguredAndCompleteThrows(): void
    {
        // No provider injected and no booted Craft app → inert defaults
        // (aiEnabled=false), so the client stays dormant and refuses to call out.
        $client = new AiClient();
        $this->assertFalse($client->isConfigured());

        $this->expectException(AiException::class);
        $client->complete('system', 'user');
    }

    private function fakeProvider(string $return): AiProviderInterface
    {
        return new class ($return) implements AiProviderInterface {
            public function __construct(private readonly string $return)
            {
            }

            public function complete(string $system, string $user, array $options = []): string
            {
                return $this->return;
            }
        };
    }
}
