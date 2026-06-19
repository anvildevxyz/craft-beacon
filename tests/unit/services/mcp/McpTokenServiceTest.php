<?php

namespace anvildev\beacon\tests\unit\services\mcp;

use anvildev\beacon\services\McpTokenService;
use PHPUnit\Framework\TestCase;

class McpTokenServiceTest extends TestCase
{
    public function testGeneratedTokenIsPrefixedAndOpaque(): void
    {
        $token = McpTokenService::generateToken();
        $this->assertStringStartsWith(McpTokenService::TOKEN_PREFIX, $token);
        // prefix (4) + 48 hex chars.
        $this->assertSame(4 + 48, strlen($token));
    }

    public function testGeneratedTokensAreUnique(): void
    {
        $this->assertNotSame(McpTokenService::generateToken(), McpTokenService::generateToken());
    }

    public function testHashIsStableAndCollisionFree(): void
    {
        $this->assertSame(McpTokenService::hashToken('abc'), McpTokenService::hashToken('abc'));
        $this->assertNotSame(McpTokenService::hashToken('abc'), McpTokenService::hashToken('abd'));
        // SHA-256 hex length.
        $this->assertSame(64, strlen(McpTokenService::hashToken('abc')));
    }

    public function testHashDoesNotLeakPlaintext(): void
    {
        $token = McpTokenService::generateToken();
        $this->assertStringNotContainsString($token, McpTokenService::hashToken($token));
    }

    public function testPrefixOfTakesLeadingChars(): void
    {
        $this->assertSame('bcn_12345678', McpTokenService::prefixOf('bcn_1234567890abcdef'));
    }
}
