<?php

namespace anvildev\beacon\services;

use anvildev\beacon\elements\RedirectElement;
use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\SeoFieldReader;
use anvildev\beacon\Plugin;
use anvildev\beacon\services\mcp\McpAuditSinkInterface;
use anvildev\beacon\services\mcp\McpAuthorizerInterface;
use anvildev\beacon\services\mcp\McpResourceDefinition;
use anvildev\beacon\services\mcp\McpRpcException;
use anvildev\beacon\services\mcp\McpServer;
use anvildev\beacon\services\mcp\McpToolDefinition;
use Craft;
use craft\elements\Entry;
use craft\elements\User;
use yii\base\Component;

/**
 * Builds an {@see McpServer} whose tools and resources are thin adapters over
 * existing Beacon services (redirects, 404 log, GEO score, IndexNow, sitemap,
 * llms.txt, meta resolution). No business logic lives here — each handler
 * resolves arguments and delegates, so the MCP surface can never diverge from
 * the CP/GraphQL behaviour.
 */
class McpService extends Component
{
    public function buildServer(
        McpAuthorizerInterface $authorizer,
        McpAuditSinkInterface $audit,
        ?User $user,
    ): McpServer {
        $server = new McpServer($authorizer, $audit, 'beacon', Plugin::$plugin->version ?? '1.0.0');
        $this->registerReadTools($server);
        $this->registerWriteTools($server, $user);
        $this->registerResources($server);
        return $server;
    }

    private function registerReadTools(McpServer $server): void
    {
        $read = BeaconPermissions::VIEW_DASHBOARD;

        $server->addTool(new McpToolDefinition(
            'beacon.get_entry_seo',
            'Resolved SEO/meta for an entry (title, description, canonical, robots).',
            $this->schema(['entryId' => 'integer'], ['siteId' => 'integer'], ['entryId']),
            fn(array $a): array => $this->getEntrySeo($a),
            $read,
        ));
        $server->addTool(new McpToolDefinition(
            'beacon.get_geo_score',
            'GEO content score and per-pillar breakdown for an entry.',
            $this->schema(['entryId' => 'integer'], ['siteId' => 'integer'], ['entryId']),
            fn(array $a): array => $this->getGeoScore($a),
            $read,
        ));
        $server->addTool(new McpToolDefinition(
            'beacon.list_redirects',
            'List redirect rules for a site.',
            $this->schema([], ['siteId' => 'integer', 'limit' => 'integer'], []),
            fn(array $a): array => $this->listRedirects($a),
            $read,
        ));
        $server->addTool(new McpToolDefinition(
            'beacon.list_404s',
            'List top unhandled 404s for a site.',
            $this->schema([], ['siteId' => 'integer', 'limit' => 'integer'], []),
            fn(array $a): array => $this->list404s($a),
            $read,
        ));
        $server->addTool(new McpToolDefinition(
            'beacon.get_llms_txt',
            'Current rendered llms.txt for a site.',
            $this->schema([], ['siteId' => 'integer'], []),
            fn(array $a): array => $this->getLlmsTxt($a),
            $read,
        ));
    }

    private function registerWriteTools(McpServer $server, ?User $user): void
    {
        $server->addTool(new McpToolDefinition(
            'beacon.create_redirect',
            'Create a redirect rule.',
            $this->schema(
                ['sourceUrl' => 'string', 'destinationUrl' => 'string'],
                ['siteId' => 'integer', 'statusCode' => 'integer', 'type' => 'string'],
                ['sourceUrl', 'destinationUrl'],
            ),
            fn(array $a): array => $this->createRedirect($a),
            BeaconPermissions::EDIT_REDIRECTS,
            readOnly: false,
        ));
        $server->addTool(new McpToolDefinition(
            'beacon.delete_redirect',
            'Delete a redirect rule by id.',
            $this->schema(['id' => 'integer'], [], ['id']),
            fn(array $a): array => $this->deleteRedirect($a),
            BeaconPermissions::EDIT_REDIRECTS,
            readOnly: false,
        ));
        $server->addTool(new McpToolDefinition(
            'beacon.set_entry_meta',
            'Set Beacon SEO fields (title, description, canonical, noindex) on an entry.',
            $this->schema(
                ['entryId' => 'integer'],
                ['siteId' => 'integer', 'title' => 'string', 'description' => 'string', 'canonical' => 'string', 'noindex' => 'boolean'],
                ['entryId'],
            ),
            fn(array $a): array => $this->setEntryMeta($a, $user),
            readOnly: false,
        ));
        $server->addTool(new McpToolDefinition(
            'beacon.recompute_geo_score',
            'Recompute the GEO score for an entry now.',
            $this->schema(['entryId' => 'integer'], ['siteId' => 'integer'], ['entryId']),
            fn(array $a): array => $this->recomputeGeoScore($a),
            BeaconPermissions::EDIT_GEO_SCORE,
            readOnly: false,
        ));
        $server->addTool(new McpToolDefinition(
            'beacon.submit_indexnow',
            'Submit a URL to IndexNow for a site.',
            $this->schema(['url' => 'string'], ['siteId' => 'integer'], ['url']),
            fn(array $a): array => $this->submitIndexNow($a),
            BeaconPermissions::EDIT_SETTINGS,
            readOnly: false,
        ));
    }

    private function registerResources(McpServer $server): void
    {
        $read = BeaconPermissions::VIEW_DASHBOARD;
        $json = 'application/json';

        $server->addResource(new McpResourceDefinition(
            'beacon://redirects',
            'Redirects',
            'All redirect rules for the primary site.',
            $json,
            fn(string $uri): string => $this->json($this->listRedirects([])),
            $read,
        ));
        $server->addResource(new McpResourceDefinition(
            'beacon://404s',
            '404 log',
            'Top unhandled 404s for the primary site.',
            $json,
            fn(string $uri): string => $this->json($this->list404s([])),
            $read,
        ));
        $server->addResource(new McpResourceDefinition(
            'beacon://llms-txt',
            'llms.txt state',
            'Current llms.txt configuration/state for the primary site.',
            $json,
            fn(string $uri): string => $this->json($this->getLlmsTxt([])),
            $read,
        ));
        $server->addResource(new McpResourceDefinition(
            'beacon://entry/{id}/seo',
            'Entry SEO',
            'Resolved SEO/meta for a specific entry.',
            $json,
            fn(string $uri): string => $this->json($this->getEntrySeo(['entryId' => $this->idFromUri($uri)])),
            $read,
            uriPattern: '~^beacon://entry/(\d+)/seo$~',
        ));
        $server->addResource(new McpResourceDefinition(
            'beacon://entry/{id}/geo-score',
            'Entry GEO score',
            'GEO score and pillars for a specific entry.',
            $json,
            fn(string $uri): string => $this->json($this->getGeoScore(['entryId' => $this->idFromUri($uri)])),
            $read,
            uriPattern: '~^beacon://entry/(\d+)/geo-score$~',
        ));
    }

    // --- handlers -----------------------------------------------------------

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function getEntrySeo(array $a): array
    {
        $entry = $this->entry($a);
        $value = SeoFieldReader::readValueFor($entry) ?? [];
        return [
            'entryId' => $entry->id,
            'siteId' => $entry->siteId,
            'title' => SeoFieldReader::headlineFor($entry),
            'description' => SeoFieldReader::readDescriptionFor($entry),
            'url' => $entry->getUrl(),
            'noindex' => SeoFieldReader::isNoIndexFor($entry),
            'fieldValue' => $value,
        ];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function getGeoScore(array $a): array
    {
        $entry = $this->entry($a);
        $score = Plugin::$plugin->geoScore->forElement((int) $entry->id, (int) $entry->siteId);
        if ($score === null) {
            return ['entryId' => $entry->id, 'score' => null, 'note' => 'No score computed yet.'];
        }
        return [
            'entryId' => $entry->id,
            'score' => $score->score,
            'pillars' => array_map(static fn($p): array => [
                'pillar' => $p->pillar,
                'score' => $p->score,
                'band' => $p->band,
                'notes' => $p->notes,
            ], $score->pillars),
        ];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function listRedirects(array $a): array
    {
        $siteId = $this->siteId($a);
        $limit = max(1, min(200, (int) ($a['limit'] ?? 50)));
        $rows = RedirectElement::find()->siteId($siteId)->limit($limit)->orderBy(['sortOrder' => SORT_ASC])->all();
        return ['siteId' => $siteId, 'redirects' => array_map(static fn(RedirectElement $r): array => [
            'id' => $r->id,
            'sourceUri' => $r->sourceUri,
            'targetUri' => $r->targetUri,
            'statusCode' => $r->statusCode,
            'type' => $r->type,
            'hits' => $r->hits,
        ], $rows)];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function list404s(array $a): array
    {
        $siteId = $this->siteId($a);
        $limit = max(1, min(200, (int) ($a['limit'] ?? 50)));
        return ['siteId' => $siteId, 'log' => Plugin::$plugin->redirect404Log->topUnhandled($siteId, $limit)];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function getLlmsTxt(array $a): array
    {
        $siteId = $this->siteId($a);
        $settings = Plugin::$plugin->siteSettings->getLlms($siteId);
        return [
            'siteId' => $siteId,
            'enabled' => $settings->enabled,
            'siteNameOverride' => $settings->siteNameOverride,
            'summary' => $settings->summary,
            'sections' => $settings->sections,
            'trust' => array_filter([
                'policyUrl' => $settings->policyUrl,
                'licenseUrl' => $settings->licenseUrl,
                'contactEmail' => $settings->contactEmail,
                'preferredAttribution' => $settings->preferredAttribution,
            ], static fn($v): bool => $v !== null && $v !== ''),
        ];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function createRedirect(array $a): array
    {
        $siteId = $this->siteId($a);
        $redirect = new RedirectElement();
        $redirect->siteId = $siteId;
        $redirect->sourceUri = trim((string) ($a['sourceUrl'] ?? ''));
        $redirect->targetUri = trim((string) ($a['destinationUrl'] ?? ''));
        $redirect->statusCode = (int) ($a['statusCode'] ?? 301);
        $redirect->type = is_string($a['type'] ?? null) ? (string) $a['type'] : 'exact';
        if ($redirect->sourceUri === '' || $redirect->targetUri === '') {
            throw new McpRpcException(McpServer::ERR_INVALID_PARAMS, 'sourceUrl and destinationUrl are required.');
        }
        if (!Craft::$app->getElements()->saveElement($redirect)) {
            throw new McpRpcException(McpServer::ERR_INTERNAL, 'Could not save redirect: ' . implode('; ', $redirect->getFirstErrors()));
        }
        return ['created' => true, 'id' => $redirect->id];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function deleteRedirect(array $a): array
    {
        $id = (int) ($a['id'] ?? 0);
        $redirect = $id > 0 ? Plugin::$plugin->redirects->findById($id) : null;
        if ($redirect === null) {
            throw new McpRpcException(McpServer::ERR_NOT_FOUND, "Unknown redirect: {$id}");
        }
        Craft::$app->getElements()->deleteElement($redirect);
        return ['deleted' => true, 'id' => $id];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function setEntryMeta(array $a, ?User $user): array
    {
        $entry = $this->entry($a);
        if ($user !== null && !$entry->canSave($user)) {
            throw new McpRpcException(McpServer::ERR_FORBIDDEN, 'Token user cannot edit this entry.');
        }
        $handle = SeoFieldReader::handleFor($entry);
        if ($handle === null) {
            throw new McpRpcException(McpServer::ERR_INVALID_PARAMS, 'Entry has no Beacon SEO field.');
        }
        $value = SeoFieldReader::readValueFor($entry) ?? [];
        foreach (['title', 'description', 'canonical'] as $key) {
            if (array_key_exists($key, $a) && is_string($a[$key])) {
                $value[$key] = $a[$key];
            }
        }
        if (array_key_exists('noindex', $a)) {
            $robots = is_array($value['robots'] ?? null) ? $value['robots'] : [];
            $robots['noindex'] = (bool) $a['noindex'];
            $value['robots'] = $robots;
        }
        $entry->setFieldValue($handle, $value);
        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new McpRpcException(McpServer::ERR_INTERNAL, 'Could not save entry: ' . implode('; ', $entry->getFirstErrors()));
        }
        return ['saved' => true, 'entryId' => $entry->id];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function recomputeGeoScore(array $a): array
    {
        $entry = $this->entry($a);
        $score = Plugin::$plugin->geoScore->compute($entry, (int) $entry->siteId, true);
        return ['entryId' => $entry->id, 'score' => $score->score];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function submitIndexNow(array $a): array
    {
        $siteId = $this->siteId($a);
        $site = Craft::$app->getSites()->getSiteById($siteId);
        if ($site === null) {
            throw new McpRpcException(McpServer::ERR_NOT_FOUND, "Unknown site: {$siteId}");
        }
        $url = trim((string) ($a['url'] ?? ''));
        if ($url === '') {
            throw new McpRpcException(McpServer::ERR_INVALID_PARAMS, 'url is required.');
        }
        $ok = Plugin::$plugin->indexNow->submitUrl($url, $site);
        return ['submitted' => $ok, 'url' => $url];
    }

    // --- helpers ------------------------------------------------------------

    /**
     * @param array<string,mixed> $a
     */
    private function entry(array $a): Entry
    {
        $id = (int) ($a['entryId'] ?? 0);
        $siteId = $this->siteId($a);
        $entry = $id > 0 ? Craft::$app->getEntries()->getEntryById($id, $siteId) : null;
        if ($entry === null) {
            throw new McpRpcException(McpServer::ERR_NOT_FOUND, "Unknown entry: {$id}");
        }
        return $entry;
    }

    /**
     * @param array<string,mixed> $a
     */
    private function siteId(array $a): int
    {
        if (isset($a['siteId']) && (int) $a['siteId'] > 0) {
            return (int) $a['siteId'];
        }
        return Craft::$app->getSites()->getPrimarySite()->id;
    }

    private function idFromUri(string $uri): int
    {
        return preg_match('~/entry/(\d+)/~', $uri, $m) === 1 ? (int) $m[1] : 0;
    }

    /**
     * Tool input JSON Schema builder.
     *
     * @param array<string,string> $required Field name → JSON type for required props.
     * @param array<string,string> $optional Field name → JSON type for optional props.
     * @param list<string> $requiredNames
     * @return array<string,mixed>
     */
    private function schema(array $required, array $optional, array $requiredNames): array
    {
        $props = [];
        foreach ([...$required, ...$optional] as $name => $type) {
            $props[$name] = ['type' => $type];
        }
        $schema = ['type' => 'object', 'properties' => $props];
        if ($requiredNames !== []) {
            $schema['required'] = $requiredNames;
        }
        return $schema;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
