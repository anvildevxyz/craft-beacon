<?php

namespace anvildev\beacon\services\mcp;

/**
 * One MCP tool: a named, schema-described action that maps to a thin adapter
 * over an existing Beacon service. The {@see McpServer} dispatches to `$handler`
 * after enforcing `$permission` (when set) and — for write tools
 * (`$readOnly === false`) — records the call through the audit sink.
 *
 * Pure value object: no Craft dependency, so the dispatch path is unit-testable
 * with fake handlers.
 */
final class McpToolDefinition
{
    /**
     * @param string $name Stable tool id, e.g. `beacon.list_redirects`.
     * @param string $description One-line human/agent-facing summary.
     * @param array<string,mixed> $inputSchema JSON Schema for the tool arguments.
     * @param (callable(array<string,mixed>): array<string,mixed>) $handler Receives validated args, returns a JSON-serialisable result.
     * @param string|null $permission Beacon permission key required to call this tool, or null for token-auth-only.
     * @param bool $readOnly When false, the call is recorded in the MCP audit log.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly mixed $handler,
        public readonly ?string $permission = null,
        public readonly bool $readOnly = true,
    ) {
    }

    /**
     * Shape expected by the MCP `tools/list` response.
     *
     * @return array{name:string,description:string,inputSchema:array<string,mixed>}
     */
    public function toListEntry(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
        ];
    }
}
