<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\services\BotRegistry;
use anvildev\beacon\services\Redirect404LogService;
use PHPUnit\Framework\TestCase;
use ReflectionObject;

/**
 * Covers the in-memory buffer + bot-filter contract. The DB write (`flush()`)
 * is exercised in the integration suite where Craft is bootstrapped.
 */
class Redirect404LogServiceTest extends TestCase
{
    public function testRecordBuffersOnce(): void
    {
        $svc = new Redirect404LogService($this->emptyRegistry());
        $this->assertTrue($svc->record(1, '/missing'));
        $this->assertSame(1, $this->buffer($svc)["1\0/missing"]['count']);
    }

    public function testRepeatedRecordCoalescesToOneRow(): void
    {
        $svc = new Redirect404LogService($this->emptyRegistry());
        $svc->record(1, '/missing');
        $svc->record(1, '/missing');
        $svc->record(1, '/missing');
        $this->assertCount(1, $this->buffer($svc));
        $this->assertSame(3, $this->buffer($svc)["1\0/missing"]['count']);
    }

    public function testDifferentSitesAreIndependent(): void
    {
        $svc = new Redirect404LogService($this->emptyRegistry());
        $svc->record(1, '/missing');
        $svc->record(2, '/missing');
        $this->assertCount(2, $this->buffer($svc));
    }

    public function testEmptyAndOversizeUrisAreRejected(): void
    {
        $svc = new Redirect404LogService($this->emptyRegistry());
        $this->assertFalse($svc->record(1, ''));
        $this->assertFalse($svc->record(1, '   '));
        $this->assertFalse($svc->record(1, '/' . str_repeat('a', 500)));
        $this->assertSame([], $this->buffer($svc));
    }

    public function testBotsAreFiltered(): void
    {
        $svc = new Redirect404LogService($this->botRegistryMatching('GPTBot/1.0'));
        $this->assertFalse($svc->record(1, '/missing', 'GPTBot/1.0'));
        $this->assertTrue($svc->record(1, '/missing', 'Mozilla/5.0 (Macintosh)'));
    }

    public function testRefererIsTruncated(): void
    {
        $svc = new Redirect404LogService($this->emptyRegistry());
        $svc->record(1, '/missing', '', 'https://example.com/' . str_repeat('a', 600));
        $this->assertSame(500, strlen((string) $this->buffer($svc)["1\0/missing"]['referer']));
    }

    private function emptyRegistry(): BotRegistry
    {
        $registry = new class extends BotRegistry {
            public function __construct() {} // skip parent's DB-touching init
            public function match(string $userAgent): ?\anvildev\beacon\models\BotDefinition
            {
                return null;
            }
        };
        return $registry;
    }

    private function botRegistryMatching(string $matchUa): BotRegistry
    {
        return new class($matchUa) extends BotRegistry {
            public function __construct(private string $matchUa) {}
            public function match(string $userAgent): ?\anvildev\beacon\models\BotDefinition
            {
                return $userAgent === $this->matchUa
                    ? new \anvildev\beacon\models\BotDefinition('Test', '/.*/')
                    : null;
            }
        };
    }

    /** @return array<string, array{siteId:int,uri:string,referer:?string,count:int}> */
    private function buffer(Redirect404LogService $svc): array
    {
        $ref = new ReflectionObject($svc);
        $prop = $ref->getProperty('pendingUpserts');
        $prop->setAccessible(true);
        /** @var array<string, array{siteId:int,uri:string,referer:?string,count:int}> $val */
        $val = $prop->getValue($svc);
        return $val;
    }
}
