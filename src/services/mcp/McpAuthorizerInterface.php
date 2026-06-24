<?php

namespace anvildev\beacon\services\mcp;

/**
 * Abstracts the identity behind an MCP request so the {@see McpServer} dispatch
 * logic stays free of Craft. The production implementation resolves a Beacon
 * API token to a Craft user and delegates to that user's permissions, so a tool
 * can never do anything the mapped user couldn't do in the Control Panel.
 */
interface McpAuthorizerInterface
{
    /** Whether the request carried a valid, enabled token mapped to a user. */
    public function isAuthenticated(): bool;

    /** Whether the mapped user holds the given Beacon permission key. */
    public function can(string $permission): bool;

    /** Mapped Craft user id, or null when unauthenticated. */
    public function userId(): ?int;

    /** Short label identifying the calling agent/token for the audit log. */
    public function agentLabel(): string;
}
