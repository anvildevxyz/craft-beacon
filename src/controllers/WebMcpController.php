<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\enums\RenderCacheType;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\Plugin;
use Craft;
use craft\models\Site;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\TooManyRequestsHttpException;

/**
 * Serves the opt-in, unauthenticated browser-side AI-agent tools script
 * (`/webmcp.js`) and the read-only JSON endpoints it calls.
 *
 * This is a public surface for an AI agent running inside a *visitor's*
 * browser — distinct from the authenticated, server-side MCP tools at
 * `/beacon/mcp` (see {@see McpController}), which target coding/editorial
 * agents and require a token.
 *
 * @author anvildev
 */
class WebMcpController extends Controller
{
    use CachedTextResponseTrait;

    public array|int|bool $allowAnonymous = true;
    public $enableCsrfValidation = false;

    /**
     * Serves the script for the current site.
     *
     * @throws NotFoundHttpException when disabled for this site
     */
    public function actionIndex(): Response
    {
        return $this->cachedTextResponse(
            RenderCacheType::WebMcp,
            'application/javascript; charset=UTF-8',
            'beacon-webmcp',
            static function(Site $site): string {
                if (!Plugin::$plugin->siteSettings->getWebMcp($site->id)->enabled) {
                    throw new NotFoundHttpException();
                }
                return Plugin::$plugin->webMcp->scriptBody($site);
            },
        );
    }

    /**
     * `GET /beacon/webmcp/search?q=&limit=` — the `searchContent` tool.
     *
     * @throws NotFoundHttpException when disabled for this site
     * @throws TooManyRequestsHttpException on rate-limit breach
     */
    public function actionSearch(): Response
    {
        $site = $this->requireEnabledSite();

        $query = (string) Http::request()->getQueryParam('q', '');
        $limit = max(1, min(25, (int) Http::request()->getQueryParam('limit', 10)));

        return $this->asJson(['results' => Plugin::$plugin->webMcp->search($site, $query, $limit)]);
    }

    /**
     * `GET /beacon/webmcp/page?url=` — the `getPage` tool.
     *
     * @throws NotFoundHttpException when disabled for this site, or the URL
     *   doesn't resolve to a live, indexable, Markdown-exportable element
     * @throws TooManyRequestsHttpException on rate-limit breach
     */
    public function actionPage(): Response
    {
        $site = $this->requireEnabledSite();

        $url = (string) Http::request()->getQueryParam('url', '');
        $page = $url !== '' ? Plugin::$plugin->webMcp->page($site, $url) : null;
        if ($page === null) {
            throw new NotFoundHttpException();
        }

        return $this->asJson($page);
    }

    /**
     * `GET /beacon/webmcp/sections` — the `listSections` tool.
     *
     * @throws NotFoundHttpException when disabled for this site
     * @throws TooManyRequestsHttpException on rate-limit breach
     */
    public function actionSections(): Response
    {
        $site = $this->requireEnabledSite();

        return $this->asJson(['sections' => Plugin::$plugin->webMcp->sections($site)]);
    }

    /**
     * Resolves the current site, 404s when the script is disabled for it —
     * the JSON tools are unreachable (and otherwise undiscoverable) without
     * the script that calls them — and rate-limits the call under a bucket
     * derived from the current action (`webmcp_search`, `webmcp_page`,
     * `webmcp_sections`).
     *
     * @throws NotFoundHttpException when disabled for this site
     * @throws TooManyRequestsHttpException on rate-limit breach
     */
    private function requireEnabledSite(): Site
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        if (!Plugin::$plugin->siteSettings->getWebMcp($site->id)->enabled) {
            throw new NotFoundHttpException();
        }
        Plugin::$plugin->geoExportThrottle->enforce('webmcp_' . ($this->action?->id ?? 'unknown'));
        return $site;
    }
}
