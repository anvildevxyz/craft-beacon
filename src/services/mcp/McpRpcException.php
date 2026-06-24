<?php

namespace anvildev\beacon\services\mcp;

use RuntimeException;

/**
 * Carries a JSON-RPC error code so {@see McpServer} can translate a thrown
 * failure into the correct `error.code` on the wire.
 */
final class McpRpcException extends RuntimeException
{
    public function __construct(public readonly int $rpcCode, string $message)
    {
        parent::__construct($message);
    }
}
