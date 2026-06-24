<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * CP screen for issuing and revoking MCP API tokens. A freshly created token is
 * flashed to the operator exactly once — only its hash is stored, so it can't be
 * shown again.
 */
class McpTokensController extends Controller
{
    use BeaconCpPermissionTrait;

    protected const BEACON_PERMISSION = BeaconPermissions::EDIT_SETTINGS;

    private const REDIRECT = 'beacon/mcp-tokens';

    public function actionIndex(): Response
    {
        return $this->renderTemplate('beacon/mcp-tokens/index', [
            'title' => Craft::t('beacon', 'settings.mcp.manageTokens'),
            'tokens' => Plugin::$plugin->mcpTokens->all(),
            'mcpEnabled' => Plugin::$plugin->settings->get()->mcpEnabled,
            'newToken' => Craft::$app->getSession()->getFlash('beaconMcpNewToken'),
        ]);
    }

    public function actionCreate(): ?Response
    {
        $this->requirePostRequest();
        $name = trim((string) Http::request()->getBodyParam('name', ''));
        $userId = (int) Craft::$app->getUser()->getId();

        [, $token] = Plugin::$plugin->mcpTokens->issue($name, $userId);
        Craft::$app->getSession()->setFlash('beaconMcpNewToken', $token);
        Craft::$app->getSession()->setNotice(Craft::t('beacon', 'settings.mcp.tokenCreated'));

        return $this->redirect(self::REDIRECT);
    }

    public function actionRevoke(): ?Response
    {
        $this->requirePostRequest();
        $id = (int) Http::request()->getBodyParam('id', 0);
        if ($id > 0 && Plugin::$plugin->mcpTokens->revoke($id)) {
            Craft::$app->getSession()->setNotice(Craft::t('beacon', 'settings.mcp.tokenRevoked'));
        }
        return $this->redirect(self::REDIRECT);
    }
}
