<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\helpers\SafeRegex;
use anvildev\beacon\helpers\Strings;
use anvildev\beacon\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class AiCrawlersController extends Controller
{
    use BeaconCpPermissionTrait;
    use JsonToggleTrait;

    protected const BEACON_PERMISSION = BeaconPermissions::EDIT_CRAWLERS;

    private const REDIRECT = 'beacon/crawlers/ai-crawlers';

    /**
     * Lists the configured AI-crawler rules and bots on the crawlers screen.
     */
    public function actionIndex(): Response
    {
        $plugin = Plugin::$plugin;
        return $this->renderTemplate('beacon/crawlers/index', [
            'selectedCrawlerTab' => 'ai-crawlers',
            'rules' => $plugin->aiCrawlers->getAllRules(),
            'bots' => $plugin->aiBots->getAllBots(),
        ]);
    }

    /**
     * Renders the AI-crawler rule edit form (or a blank one for a new rule).
     *
     * @throws \yii\web\NotFoundHttpException when $ruleId is given but no matching rule exists
     */
    public function actionEditRule(?int $ruleId = null): Response
    {
        $plugin = Plugin::$plugin;
        $rule = $ruleId !== null ? $plugin->aiCrawlers->findRule($ruleId) : null;
        if ($ruleId !== null && $rule === null) {
            throw new NotFoundHttpException(Craft::t('beacon', 'Rule not found'));
        }
        return $this->renderTemplate('beacon/ai-crawlers/_edit-rule', [
            'rule' => $rule,
            'bots' => $plugin->aiBots->getAllBots(),
        ]);
    }

    /**
     * Creates or updates an AI-crawler rule from the posted form and redirects
     * back to the crawlers screen.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     */
    public function actionSaveRule(): ?Response
    {
        $this->requirePostRequest();
        $request = Http::request();
        $rawId = $request->getBodyParam('ruleId');

        Plugin::$plugin->aiCrawlers->saveRule(
            id: is_numeric($rawId) ? (int) $rawId : null,
            botName: (string) $request->getBodyParam('botName', ''),
            allowPaths: Strings::splitLines((string) $request->getBodyParam('allowPaths', '')),
            disallowPaths: Strings::splitLines((string) $request->getBodyParam('disallowPaths', '')),
            enabled: (bool) $request->getBodyParam('enabled', true),
        );

        Craft::$app->getSession()->setNotice(Craft::t('beacon', 'Rule saved.'));
        return $this->redirect(self::REDIRECT);
    }

    /**
     * Deletes the AI-crawler rule identified by the posted `ruleId`.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     */
    public function actionDeleteRule(): ?Response
    {
        $this->requirePostRequest();
        Plugin::$plugin->aiCrawlers->deleteRule((int) Http::request()->getBodyParam('ruleId'));
        Craft::$app->getSession()->setNotice(Craft::t('beacon', 'Rule deleted.'));
        return $this->redirect(self::REDIRECT);
    }

    /**
     * Renders the AI-bot edit form (or a blank one for a new bot).
     *
     * @throws \yii\web\NotFoundHttpException when $botId is given but no matching bot exists
     */
    public function actionEditBot(?int $botId = null): Response
    {
        $bot = $botId !== null ? Plugin::$plugin->aiBots->findBot($botId) : null;
        if ($botId !== null && $bot === null) {
            throw new NotFoundHttpException(Craft::t('beacon', 'Bot not found'));
        }
        return $this->renderTemplate('beacon/ai-crawlers/_edit-bot', ['bot' => $bot]);
    }

    /**
     * Creates or updates an AI-bot definition from the posted form, validating
     * its user-agent pattern before saving, then redirects to the crawlers screen.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     */
    public function actionSaveBot(): ?Response
    {
        $this->requirePostRequest();
        $request = Http::request();
        $rawId = $request->getBodyParam('botId');
        $userAgentPattern = (string) $request->getBodyParam('userAgentPattern', '');

        if (($err = SafeRegex::validate($userAgentPattern)) !== null) {
            Craft::$app->getSession()->setError(Craft::t('beacon', $err));
            return $this->redirect(self::REDIRECT);
        }

        Plugin::$plugin->aiBots->saveBot(
            id: is_numeric($rawId) ? (int) $rawId : null,
            name: (string) $request->getBodyParam('name', ''),
            userAgentPattern: $userAgentPattern,
            enabled: (bool) $request->getBodyParam('enabled', true),
        );

        Craft::$app->getSession()->setNotice(Craft::t('beacon', 'Bot saved.'));
        return $this->redirect(self::REDIRECT);
    }

    /**
     * Deletes the AI-bot identified by the posted `botId`.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     */
    public function actionDeleteBot(): ?Response
    {
        $this->requirePostRequest();
        Plugin::$plugin->aiBots->deleteBot((int) Http::request()->getBodyParam('botId'));
        Craft::$app->getSession()->setNotice(Craft::t('beacon', 'Bot deleted.'));
        return $this->redirect(self::REDIRECT);
    }

    /**
     * Restores the built-in default AI bots and redirects to the crawlers screen.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     */
    public function actionResetDefaults(): ?Response
    {
        $this->requirePostRequest();
        $count = Plugin::$plugin->aiBots->resetDefaults();
        Craft::$app->getSession()->setNotice(Craft::t('beacon', '{count} default bots restored.', ['count' => $count]));
        return $this->redirect(self::REDIRECT);
    }

    /**
     * Toggles the enabled state of the posted bot and returns a JSON result.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST or doesn't accept JSON
     * @throws \yii\web\NotFoundHttpException when no matching bot exists
     */
    public function actionToggleBot(): Response
    {
        return $this->toggleEnabled('botId', static fn(int $id, bool $on) => Plugin::$plugin->aiBots->setBotEnabled($id, $on), Craft::t('beacon', 'Bot not found'));
    }

    /**
     * Toggles the enabled state of the posted rule and returns a JSON result.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST or doesn't accept JSON
     * @throws \yii\web\NotFoundHttpException when no matching rule exists
     */
    public function actionToggleRule(): Response
    {
        return $this->toggleEnabled('ruleId', static fn(int $id, bool $on) => Plugin::$plugin->aiCrawlers->setRuleEnabled($id, $on), Craft::t('beacon', 'Rule not found'));
    }
}
