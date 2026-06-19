<?php

namespace anvildev\beacon\services\mcp;

use Throwable;

/**
 * Minimal, self-contained MCP server speaking JSON-RPC 2.0. Holds a registry of
 * tools and resources (thin adapters over Beacon services), enforces Beacon
 * permissions through an {@see McpAuthorizerInterface}, and records write-tool
 * calls through an {@see McpAuditSinkInterface}.
 *
 * Deliberately Craft-free so the whole request → permission → dispatch → audit
 * path is unit-testable with fakes. Craft wiring lives in
 * {@see \anvildev\beacon\services\McpService} and the controller.
 */
final class McpServer
{
    public const PROTOCOL_VERSION = '2024-11-05';

    // JSON-RPC reserved codes.
    public const ERR_PARSE = -32700;
    public const ERR_INVALID_REQUEST = -32600;
    public const ERR_METHOD_NOT_FOUND = -32601;
    public const ERR_INVALID_PARAMS = -32602;
    public const ERR_INTERNAL = -32603;
    // Beacon application codes.
    public const ERR_UNAUTHORIZED = -32001;
    public const ERR_FORBIDDEN = -32002;
    public const ERR_NOT_FOUND = -32003;

    /** @var array<string,McpToolDefinition> */
    private array $tools = [];

    /** @var list<McpResourceDefinition> */
    private array $resources = [];

    public function __construct(
        private readonly McpAuthorizerInterface $authorizer,
        private readonly McpAuditSinkInterface $audit,
        private readonly string $serverName = 'beacon',
        private readonly string $serverVersion = '1.0.0',
    ) {
    }

    public function addTool(McpToolDefinition $tool): void
    {
        $this->tools[$tool->name] = $tool;
    }

    public function addResource(McpResourceDefinition $resource): void
    {
        $this->resources[] = $resource;
    }

    /**
     * Handle a single decoded JSON-RPC request. Returns the response array, or
     * null for a notification (a request with no `id`), which must produce no
     * reply per JSON-RPC.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>|null
     */
    public function handle(array $request): ?array
    {
        $id = $request['id'] ?? null;
        $isNotification = !array_key_exists('id', $request);

        if (($request['jsonrpc'] ?? null) !== '2.0' || !isset($request['method']) || !is_string($request['method'])) {
            return $isNotification ? null : $this->error($id, self::ERR_INVALID_REQUEST, 'Invalid JSON-RPC request');
        }

        $method = $request['method'];
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];

        // Auth gate: everything except the handshake/ping needs a valid token.
        if (!in_array($method, ['initialize', 'notifications/initialized', 'ping'], true)
            && !$this->authorizer->isAuthenticated()) {
            return $isNotification ? null : $this->error($id, self::ERR_UNAUTHORIZED, 'Missing or invalid API token');
        }

        try {
            $result = match ($method) {
                'initialize' => $this->initialize(),
                'notifications/initialized', 'ping' => [],
                'tools/list' => ['tools' => array_map(static fn(McpToolDefinition $t): array => $t->toListEntry(), array_values($this->tools))],
                'tools/call' => $this->callTool($params),
                'resources/list' => ['resources' => array_map(static fn(McpResourceDefinition $r): array => $r->toListEntry(), $this->resources)],
                'resources/read' => $this->readResource($params),
                default => throw new McpRpcException(self::ERR_METHOD_NOT_FOUND, "Unknown method: {$method}"),
            };
        } catch (McpRpcException $e) {
            return $isNotification ? null : $this->error($id, $e->rpcCode, $e->getMessage());
        } catch (Throwable $e) {
            return $isNotification ? null : $this->error($id, self::ERR_INTERNAL, $e->getMessage());
        }

        return $isNotification ? null : ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @return array<string,mixed>
     */
    private function initialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => ['listChanged' => false],
            ],
            'serverInfo' => ['name' => $this->serverName, 'version' => $this->serverVersion],
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function callTool(array $params): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || !isset($this->tools[$name])) {
            throw new McpRpcException(self::ERR_INVALID_PARAMS, 'Unknown tool: ' . (is_string($name) ? $name : '(none)'));
        }
        $tool = $this->tools[$name];
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if ($tool->permission !== null && !$this->authorizer->can($tool->permission)) {
            throw new McpRpcException(self::ERR_FORBIDDEN, "Permission denied for tool: {$name}");
        }

        try {
            /** @var array<string,mixed> $result */
            $result = ($tool->handler)($arguments);
        } catch (Throwable $e) {
            if (!$tool->readOnly) {
                $this->audit->record($name, $arguments, false, $e->getMessage());
            }
            // Surface as an MCP tool error (isError content), not a transport fault.
            return $this->toolContent($e->getMessage(), true);
        }

        if (!$tool->readOnly) {
            $this->audit->record($name, $arguments, true, null);
        }

        return $this->toolContent((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), false);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function readResource(array $params): array
    {
        $uri = $params['uri'] ?? null;
        if (!is_string($uri)) {
            throw new McpRpcException(self::ERR_INVALID_PARAMS, 'Missing resource uri');
        }
        foreach ($this->resources as $resource) {
            if (!$resource->matches($uri)) {
                continue;
            }
            if ($resource->permission !== null && !$this->authorizer->can($resource->permission)) {
                throw new McpRpcException(self::ERR_FORBIDDEN, "Permission denied for resource: {$uri}");
            }
            $body = ($resource->reader)($uri);
            return ['contents' => [['uri' => $uri, 'mimeType' => $resource->mimeType, 'text' => $body]]];
        }
        throw new McpRpcException(self::ERR_NOT_FOUND, "Unknown resource: {$uri}");
    }

    /**
     * @return array{content:list<array{type:string,text:string}>,isError:bool}
     */
    private function toolContent(string $text, bool $isError): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $isError];
    }

    /**
     * @return array{jsonrpc:string,id:mixed,error:array{code:int,message:string}}
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
