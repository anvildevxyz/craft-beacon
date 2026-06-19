<?php

namespace anvildev\beacon\services\mcp;

use anvildev\beacon\records\McpAuditLogRecord;

/**
 * Writes MCP write-tool calls to {{%beacon_mcp_audit_log}}. Arguments are JSON
 * encoded; errors are best-effort and never interrupt the request.
 */
final class DbMcpAuditSink implements McpAuditSinkInterface
{
    public function __construct(
        private readonly ?int $tokenId,
        private readonly ?int $userId,
        private readonly string $agentLabel,
    ) {
    }

    public function record(string $tool, array $arguments, bool $ok, ?string $error = null): void
    {
        $record = new McpAuditLogRecord();
        $record->tokenId = $this->tokenId;
        $record->userId = $this->userId;
        $record->agentLabel = $this->agentLabel;
        $record->tool = $tool;
        $record->arguments = (string) json_encode($arguments, JSON_UNESCAPED_SLASHES);
        $record->success = $ok;
        $record->error = $error;
        $record->save(false);
    }
}
