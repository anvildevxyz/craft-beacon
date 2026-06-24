<?php

namespace anvildev\beacon\services\mcp;

/**
 * One MCP resource: a readable, URI-addressable view over Beacon data
 * (e.g. `beacon://redirects`). Read-only by definition; the matching read is
 * served by `$reader`, which receives the concrete URI (so templated resources
 * can parse ids out of it).
 *
 * Pure value object — no Craft dependency.
 */
final class McpResourceDefinition
{
    /**
     * @param string $uri Concrete or templated URI, e.g. `beacon://entry/{id}/seo`.
     * @param string $name Short label.
     * @param string $description One-line summary.
     * @param string $mimeType MIME type of the read payload (usually `application/json`).
     * @param (callable(string): string) $reader Receives the requested URI, returns the resource body.
     * @param string|null $permission Beacon permission key required to read, or null for token-auth-only.
     * @param string|null $uriPattern PCRE matching concrete URIs for a templated resource; null = exact `$uri` match.
     */
    public function __construct(
        public readonly string $uri,
        public readonly string $name,
        public readonly string $description,
        public readonly string $mimeType,
        public readonly mixed $reader,
        public readonly ?string $permission = null,
        public readonly ?string $uriPattern = null,
    ) {
    }

    public function matches(string $uri): bool
    {
        if ($this->uriPattern !== null) {
            return preg_match($this->uriPattern, $uri) === 1;
        }
        return $uri === $this->uri;
    }

    /**
     * Shape expected by the MCP `resources/list` response.
     *
     * @return array{uri:string,name:string,description:string,mimeType:string}
     */
    public function toListEntry(): array
    {
        return [
            'uri' => $this->uri,
            'name' => $this->name,
            'description' => $this->description,
            'mimeType' => $this->mimeType,
        ];
    }
}
