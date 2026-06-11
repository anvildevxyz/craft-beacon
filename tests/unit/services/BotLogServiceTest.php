<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\models\BotDefinition;
use anvildev\beacon\services\BotLogService;
use anvildev\beacon\services\BotRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionObject;

class BotLogServiceTest extends TestCase
{
    public function testTruncatesPathTo255(): void
    {
        $service = new BotLogService(new BotRegistry());
        $long = str_repeat('a', 300);
        $this->assertSame(255, strlen($service->normalizePath('/' . $long)));
    }

    public function testRetentionCutoffComputed(): void
    {
        $service = new BotLogService(new BotRegistry());
        $cutoff = $service->retentionCutoff(30);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cutoff);
    }

    public function testNonBotIsNotBuffered(): void
    {
        $service = new BotLogService($this->registryMatching(null));
        $this->assertFalse($service->logIfBot('Mozilla/5.0 (Macintosh)', '/home', 1));
        $this->assertSame([], $this->buffer($service));
    }

    public function testBotHitIsBufferedNotWrittenInline(): void
    {
        $service = new BotLogService($this->registryMatching('GPTBot'));
        $this->assertTrue($service->logIfBot('GPTBot/1.0', '/article', 2));

        // The hit lives in the in-memory buffer; the DB write only happens on
        // flush() (EVENT_AFTER_SEND), keeping it off the pre-request path.
        $buffer = $this->buffer($service);
        $this->assertCount(1, $buffer);
        $this->assertSame(['siteId' => 2, 'botName' => 'GPTBot', 'path' => '/article'], $buffer[0]);
    }

    public function testNormalizesBufferedPath(): void
    {
        $service = new BotLogService($this->registryMatching('GPTBot'));
        $service->logIfBot('GPTBot/1.0', '/' . str_repeat('a', 400), 1);
        $this->assertSame(255, strlen($this->buffer($service)[0]['path']));
    }

    public function testFlushOnEmptyBufferIsNoop(): void
    {
        $service = new BotLogService($this->registryMatching(null));
        // No DB available in the unit suite; an empty buffer must early-return
        // before ever touching Craft::$app, so this must not throw.
        $service->flush();
        $this->assertSame([], $this->buffer($service));
    }

    private function registryMatching(?string $matchName): BotRegistry
    {
        return new class($matchName) extends BotRegistry {
            public function __construct(private ?string $matchName) {}

            public function match(string $userAgent): ?BotDefinition
            {
                return $this->matchName !== null && str_contains($userAgent, $this->matchName)
                    ? new BotDefinition($this->matchName, '/.*/')
                    : null;
            }
        };
    }

    /** @return list<array{siteId:int,botName:string,path:string}> */
    private function buffer(BotLogService $service): array
    {
        $prop = (new ReflectionObject($service))->getProperty('pending');
        $prop->setAccessible(true);
        /** @var list<array{siteId:int,botName:string,path:string}> $val */
        $val = $prop->getValue($service);
        return $val;
    }
}
