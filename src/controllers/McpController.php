<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\Http;
use anvildev\beacon\Plugin;
use anvildev\beacon\services\mcp\CraftMcpAuthorizer;
use anvildev\beacon\services\mcp\DbMcpAuditSink;
use anvildev\beacon\services\mcp\McpServer;
use Craft;
use craft\elements\User;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * JSON-RPC 2.0 transport for the MCP server at `POST /beacon/mcp`.
 *
 * Disabled (404) unless `mcpEnabled` is on. Authentication is a Bearer API
 * token (`Authorization: Bearer bcn_…`) mapped to a Craft user; the
 * {@see McpServer} enforces that user's Beacon permissions on every tool.
 * Returns 404 — not 403 — when disabled so the endpoint's existence isn't
 * leaked on sites that don't use it.
 */
class McpController extends Controller
{
    public array|int|bool $allowAnonymous = true;
    public $enableCsrfValidation = false;

    public function actionIndex(): Response
    {
        if (!Plugin::$plugin->settings->get()->mcpEnabled) {
            throw new NotFoundHttpException();
        }

        $this->requirePostRequest();

        // Resolve the Bearer token → token record → Craft user (or null).
        $token = $this->bearerToken();
        $user = null;
        $tokenId = null;
        $agentLabel = 'mcp';
        if ($token !== null) {
            $record = Plugin::$plugin->mcpTokens->resolve($token);
            if ($record !== null) {
                $tokenId = (int) $record->id;
                $agentLabel = $record->name;
                $found = Craft::$app->getUsers()->getUserById((int) $record->userId);
                $user = $found instanceof User ? $found : null;
            }
        }

        $authorizer = new CraftMcpAuthorizer($user, $agentLabel);
        $audit = new DbMcpAuditSink($tokenId, $user?->id, $agentLabel);
        $server = Plugin::$plugin->mcp->buildServer($authorizer, $audit, $user);

        $raw = (string) Http::request()->getRawBody();
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $this->asJson([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => McpServer::ERR_PARSE, 'message' => 'Parse error'],
            ]);
        }

        // Support a single request or a JSON-RPC batch.
        if (array_is_list($decoded)) {
            $responses = [];
            foreach ($decoded as $request) {
                if (is_array($request)) {
                    $response = $server->handle($request);
                    if ($response !== null) {
                        $responses[] = $response;
                    }
                }
            }
            return $this->asJson($responses);
        }

        $response = $server->handle($decoded);
        return $this->asJson($response ?? ['jsonrpc' => '2.0', 'id' => null, 'result' => null]);
    }

    private function bearerToken(): ?string
    {
        $header = (string) Http::request()->getHeaders()->get('Authorization', '');
        if (preg_match('~^Bearer\s+(.+)$~i', $header, $m) === 1) {
            return trim($m[1]);
        }
        return null;
    }
}
