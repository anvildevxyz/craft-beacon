<?php

namespace anvildev\beacon\services\mcp;

use craft\elements\User;

/**
 * Production authorizer: delegates every permission check to the Craft user the
 * token maps to, so an MCP tool can never exceed that user's Control-Panel
 * rights. An unresolved token yields an unauthenticated authorizer.
 */
final class CraftMcpAuthorizer implements McpAuthorizerInterface
{
    public function __construct(
        private readonly ?User $user,
        private readonly string $agentLabel = 'mcp',
    ) {
    }

    public function isAuthenticated(): bool
    {
        return $this->user !== null;
    }

    public function can(string $permission): bool
    {
        return $this->user !== null && $this->user->can($permission);
    }

    public function userId(): ?int
    {
        return $this->user?->id;
    }

    public function agentLabel(): string
    {
        return $this->agentLabel;
    }
}
