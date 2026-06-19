<?php

namespace anvildev\beacon\services\mcp;

/**
 * Records MCP write-tool invocations. The production sink writes a row to
 * {{%beacon_mcp_audit_log}}; tests use an in-memory fake.
 */
interface McpAuditSinkInterface
{
    /**
     * @param string $tool Tool name that was invoked.
     * @param array<string,mixed> $arguments Arguments passed to the tool.
     * @param bool $ok Whether the call succeeded.
     * @param string|null $error Error message when `$ok` is false.
     */
    public function record(string $tool, array $arguments, bool $ok, ?string $error = null): void;
}
