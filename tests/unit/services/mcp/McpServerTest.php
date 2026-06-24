<?php

namespace anvildev\beacon\tests\unit\services\mcp;

use anvildev\beacon\services\mcp\McpAuditSinkInterface;
use anvildev\beacon\services\mcp\McpAuthorizerInterface;
use anvildev\beacon\services\mcp\McpResourceDefinition;
use anvildev\beacon\services\mcp\McpServer;
use anvildev\beacon\services\mcp\McpToolDefinition;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class McpServerTest extends TestCase
{
    public function testInitializeWorksWithoutAuth(): void
    {
        $server = $this->server($this->authorizer(false));
        $res = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
        $this->assertSame('beacon', $res['result']['serverInfo']['name']);
        $this->assertSame(McpServer::PROTOCOL_VERSION, $res['result']['protocolVersion']);
    }

    public function testNonHandshakeMethodRequiresAuth(): void
    {
        $server = $this->server($this->authorizer(false));
        $res = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
        $this->assertSame(McpServer::ERR_UNAUTHORIZED, $res['error']['code']);
    }

    public function testToolsListReturnsRegisteredTools(): void
    {
        $server = $this->server($this->authorizer(true));
        $server->addTool($this->echoTool());
        $res = $server->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list']);
        $this->assertCount(1, $res['result']['tools']);
        $this->assertSame('echo', $res['result']['tools'][0]['name']);
    }

    public function testToolCallRunsHandler(): void
    {
        $server = $this->server($this->authorizer(true));
        $server->addTool($this->echoTool());
        $res = $server->handle([
            'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
            'params' => ['name' => 'echo', 'arguments' => ['msg' => 'hi']],
        ]);
        $this->assertFalse($res['result']['isError']);
        $this->assertStringContainsString('hi', $res['result']['content'][0]['text']);
    }

    public function testToolCallDeniedWhenPermissionMissing(): void
    {
        $server = $this->server($this->authorizer(true, can: false));
        $server->addTool(new McpToolDefinition('locked', 'd', [], static fn(array $a): array => ['ok' => true], 'beacon:editRedirects'));
        $res = $server->handle([
            'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call',
            'params' => ['name' => 'locked'],
        ]);
        $this->assertSame(McpServer::ERR_FORBIDDEN, $res['error']['code']);
    }

    public function testWriteToolRecordsAuditOnSuccess(): void
    {
        $audit = $this->audit();
        $server = $this->server($this->authorizer(true), $audit);
        $server->addTool(new McpToolDefinition('w', 'd', [], static fn(array $a): array => ['done' => true], null, readOnly: false));
        $server->handle(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'w']]);
        $this->assertCount(1, $audit->records);
        $this->assertTrue($audit->records[0]['ok']);
    }

    public function testWriteToolRecordsAuditOnFailureAndReturnsToolError(): void
    {
        $audit = $this->audit();
        $server = $this->server($this->authorizer(true), $audit);
        $server->addTool(new McpToolDefinition('boom', 'd', [], static function(array $a): array {
            throw new RuntimeException('kaboom');
        }, null, readOnly: false));
        $res = $server->handle(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'boom']]);
        $this->assertTrue($res['result']['isError']);
        $this->assertCount(1, $audit->records);
        $this->assertFalse($audit->records[0]['ok']);
        $this->assertSame('kaboom', $audit->records[0]['error']);
    }

    public function testReadToolDoesNotAudit(): void
    {
        $audit = $this->audit();
        $server = $this->server($this->authorizer(true), $audit);
        $server->addTool($this->echoTool());
        $server->handle(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call', 'params' => ['name' => 'echo']]);
        $this->assertCount(0, $audit->records);
    }

    public function testResourcesListAndRead(): void
    {
        $server = $this->server($this->authorizer(true));
        $server->addResource(new McpResourceDefinition('beacon://x', 'X', 'desc', 'application/json', static fn(string $u): string => '{"a":1}'));
        $list = $server->handle(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'resources/list']);
        $this->assertSame('beacon://x', $list['result']['resources'][0]['uri']);
        $read = $server->handle(['jsonrpc' => '2.0', 'id' => 10, 'method' => 'resources/read', 'params' => ['uri' => 'beacon://x']]);
        $this->assertSame('{"a":1}', $read['result']['contents'][0]['text']);
    }

    public function testTemplatedResourceMatchesByPattern(): void
    {
        $server = $this->server($this->authorizer(true));
        $server->addResource(new McpResourceDefinition(
            'beacon://entry/{id}/seo',
            'Entry',
            'desc',
            'application/json',
            static fn(string $u): string => $u,
            null,
            uriPattern: '~^beacon://entry/(\d+)/seo$~',
        ));
        $read = $server->handle(['jsonrpc' => '2.0', 'id' => 11, 'method' => 'resources/read', 'params' => ['uri' => 'beacon://entry/42/seo']]);
        $this->assertSame('beacon://entry/42/seo', $read['result']['contents'][0]['text']);
    }

    public function testUnknownResourceReturnsNotFound(): void
    {
        $server = $this->server($this->authorizer(true));
        $res = $server->handle(['jsonrpc' => '2.0', 'id' => 12, 'method' => 'resources/read', 'params' => ['uri' => 'beacon://nope']]);
        $this->assertSame(McpServer::ERR_NOT_FOUND, $res['error']['code']);
    }

    public function testUnknownMethodReturnsMethodNotFound(): void
    {
        $server = $this->server($this->authorizer(true));
        $res = $server->handle(['jsonrpc' => '2.0', 'id' => 13, 'method' => 'no/such']);
        $this->assertSame(McpServer::ERR_METHOD_NOT_FOUND, $res['error']['code']);
    }

    public function testNotificationProducesNoResponse(): void
    {
        $server = $this->server($this->authorizer(true));
        // No `id` key → notification → null reply.
        $this->assertNull($server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
    }

    public function testInvalidEnvelopeRejected(): void
    {
        $server = $this->server($this->authorizer(true));
        $res = $server->handle(['id' => 14, 'method' => 'tools/list']);
        $this->assertSame(McpServer::ERR_INVALID_REQUEST, $res['error']['code']);
    }

    // --- helpers ------------------------------------------------------------

    private function server(McpAuthorizerInterface $authorizer, ?McpAuditSinkInterface $audit = null): McpServer
    {
        return new McpServer($authorizer, $audit ?? $this->audit());
    }

    private function echoTool(): McpToolDefinition
    {
        return new McpToolDefinition('echo', 'Echo', [], static fn(array $a): array => ['echoed' => $a['msg'] ?? null]);
    }

    private function authorizer(bool $authenticated, bool $can = true): McpAuthorizerInterface
    {
        return new class ($authenticated, $can) implements McpAuthorizerInterface {
            public function __construct(private bool $authenticated, private bool $can)
            {
            }

            public function isAuthenticated(): bool
            {
                return $this->authenticated;
            }

            public function can(string $permission): bool
            {
                return $this->can;
            }

            public function userId(): ?int
            {
                return $this->authenticated ? 1 : null;
            }

            public function agentLabel(): string
            {
                return 'test';
            }
        };
    }

    private function audit(): RecordingAuditSink
    {
        return new RecordingAuditSink();
    }
}

/**
 * Named recording double so PHPStan can see the `$records` property the
 * assertions read (an anonymous class would be typed only as the interface).
 */
final class RecordingAuditSink implements McpAuditSinkInterface
{
    /** @var list<array{tool:string,ok:bool,error:?string}> */
    public array $records = [];

    public function record(string $tool, array $arguments, bool $ok, ?string $error = null): void
    {
        $this->records[] = ['tool' => $tool, 'ok' => $ok, 'error' => $error];
    }
}
