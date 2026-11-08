<?php

namespace anvildev\beacon\mcp;

use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\Plugin;
use craft\mcp\attributes\RequiresPermission;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/**
 * Craft 5.12 MCP tools for Beacon. Each method delegates to
 * {@see \anvildev\beacon\services\McpService::callTool()}, which is the same
 * handler the plugin's own HTTP MCP server uses, so the two surfaces cannot
 * drift. The HTTP server, its tokens, and its audit log are unchanged.
 */
class CraftTools
{
    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'beacon_get_entry_seo',
        description: 'Resolved SEO/meta for an entry (title, description, canonical, robots).',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission(BeaconPermissions::VIEW_DASHBOARD)]
    public function getEntrySeo(int $entryId, ?int $siteId = null): array
    {
        return $this->call('beacon.get_entry_seo', $entryId, $siteId);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'beacon_get_geo_score',
        description: 'GEO content score and per-pillar breakdown for an entry.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission(BeaconPermissions::VIEW_DASHBOARD)]
    public function getGeoScore(int $entryId, ?int $siteId = null): array
    {
        return $this->call('beacon.get_geo_score', $entryId, $siteId);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'beacon_list_redirects',
        description: 'List redirect rules for a site.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission(BeaconPermissions::VIEW_DASHBOARD)]
    public function listRedirects(?int $siteId = null, ?int $limit = null): array
    {
        return $this->call('beacon.list_redirects', null, $siteId, ['limit' => $limit]);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'beacon_list_404s',
        description: 'List top unhandled 404s for a site.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission(BeaconPermissions::VIEW_DASHBOARD)]
    public function list404s(?int $siteId = null, ?int $limit = null): array
    {
        return $this->call('beacon.list_404s', null, $siteId, ['limit' => $limit]);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'beacon_get_llms_txt',
        description: 'Current rendered llms.txt settings for a site.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission(BeaconPermissions::VIEW_DASHBOARD)]
    public function getLlmsTxt(?int $siteId = null): array
    {
        return $this->call('beacon.get_llms_txt', null, $siteId);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'beacon_create_redirect',
        description: 'Create a redirect rule.',
        annotations: new ToolAnnotations(destructiveHint: true),
    )]
    #[RequiresPermission(BeaconPermissions::EDIT_REDIRECTS)]
    public function createRedirect(string $sourceUrl, string $destinationUrl, ?int $siteId = null, ?int $statusCode = null, ?string $type = null): array
    {
        return $this->call('beacon.create_redirect', null, $siteId, [
            'sourceUrl' => $sourceUrl,
            'destinationUrl' => $destinationUrl,
            'statusCode' => $statusCode,
            'type' => $type,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'beacon_delete_redirect',
        description: 'Delete a redirect rule by id.',
        annotations: new ToolAnnotations(destructiveHint: true),
    )]
    #[RequiresPermission(BeaconPermissions::EDIT_REDIRECTS)]
    public function deleteRedirect(int $id): array
    {
        return $this->call('beacon.delete_redirect', null, null, ['id' => $id]);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'beacon_set_entry_meta',
        description: 'Set Beacon SEO fields (title, description, canonical, noindex) on an entry.',
        annotations: new ToolAnnotations(destructiveHint: true),
    )]
    #[RequiresPermission(BeaconPermissions::EDIT_SETTINGS)]
    public function setEntryMeta(int $entryId, ?int $siteId = null, ?string $title = null, ?string $description = null, ?string $canonical = null, ?bool $noindex = null): array
    {
        return $this->call('beacon.set_entry_meta', $entryId, $siteId, [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'noindex' => $noindex,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'beacon_recompute_geo_score',
        description: 'Recompute the GEO score for an entry now.',
        annotations: new ToolAnnotations(destructiveHint: true),
    )]
    #[RequiresPermission(BeaconPermissions::EDIT_GEO_SCORE)]
    public function recomputeGeoScore(int $entryId, ?int $siteId = null): array
    {
        return $this->call('beacon.recompute_geo_score', $entryId, $siteId);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'beacon_submit_indexnow',
        description: 'Submit a URL to IndexNow for a site.',
        annotations: new ToolAnnotations(destructiveHint: true),
    )]
    #[RequiresPermission(BeaconPermissions::EDIT_SETTINGS)]
    public function submitIndexNow(string $url, ?int $siteId = null): array
    {
        return $this->call('beacon.submit_indexnow', null, $siteId, ['url' => $url]);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function call(string $name, ?int $entryId, ?int $siteId = null, array $extra = []): array
    {
        $arguments = $extra;
        if ($entryId !== null) {
            $arguments['entryId'] = $entryId;
        }
        if ($siteId !== null) {
            $arguments['siteId'] = $siteId;
        }
        foreach ($arguments as $key => $value) {
            if ($value === null) {
                unset($arguments[$key]);
            }
        }

        /** @var \anvildev\beacon\services\McpService $mcp */
        $mcp = Plugin::$plugin->get('mcp');

        return $mcp->callTool($name, $arguments);
    }
}
