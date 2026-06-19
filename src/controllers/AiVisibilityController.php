<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\helpers\Strings;
use anvildev\beacon\jobs\CheckAiVisibilityJob;
use anvildev\beacon\models\BenchmarkPrompt;
use anvildev\beacon\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * CP screen for answer-engine visibility tracking: manage per-site benchmark
 * prompts, trigger a run, and review the latest probe results.
 */
class AiVisibilityController extends Controller
{
    use BeaconCpPermissionTrait;

    protected const BEACON_PERMISSION = BeaconPermissions::EDIT_AI_VISIBILITY;

    private const REDIRECT = 'beacon/ai-visibility';

    public function actionIndex(): Response
    {
        $plugin = Plugin::$plugin;
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $settings = $plugin->settings->get();

        return $this->renderTemplate('beacon/ai-visibility/index', [
            'title' => Craft::t('beacon', 'aiVisibility.title'),
            'prompts' => $plugin->aiVisibility->getPrompts($siteId),
            'results' => $plugin->aiVisibility->latestResults($siteId, 50),
            'citationRate' => $plugin->aiVisibility->citationRate($siteId, 30),
            'isActive' => $plugin->aiVisibility->isActive($settings),
            'isEnabled' => $settings->aiVisibilityEnabled,
            'isProviderConfigured' => $plugin->aiClient->isConfigured(),
        ]);
    }

    public function actionSavePrompt(): ?Response
    {
        $this->requirePostRequest();
        $request = Http::request();
        $rawId = $request->getBodyParam('promptId');
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $saved = Plugin::$plugin->aiVisibility->savePrompt(new BenchmarkPrompt(
            id: is_numeric($rawId) ? (int) $rawId : null,
            siteId: $siteId,
            prompt: (string) $request->getBodyParam('prompt', ''),
            enabled: (bool) $request->getBodyParam('enabled', true),
        ));

        if (!$saved) {
            Craft::$app->getSession()->setError(Craft::t('beacon', 'aiVisibility.flash.prompt.invalid'));
            return $this->redirect(self::REDIRECT);
        }
        Craft::$app->getSession()->setNotice(Craft::t('beacon', 'aiVisibility.flash.prompt.saved'));
        return $this->redirect(self::REDIRECT);
    }

    public function actionDeletePrompt(): ?Response
    {
        $this->requirePostRequest();
        $rawId = Http::request()->getBodyParam('promptId');
        if (is_numeric($rawId)) {
            Plugin::$plugin->aiVisibility->deletePrompt((int) $rawId);
        }
        Craft::$app->getSession()->setNotice(Craft::t('beacon', 'aiVisibility.flash.prompt.deleted'));
        return $this->redirect(self::REDIRECT);
    }

    public function actionSaveSettings(): ?Response
    {
        $this->requirePostRequest();
        $request = Http::request();
        $settings = clone Plugin::$plugin->settings->get();

        $settings->aiVisibilityEnabled = (bool) $request->getBodyParam('aiVisibilityEnabled', false);
        $settings->aiVisibilityCadence = in_array($request->getBodyParam('aiVisibilityCadence'), ['off', 'daily', 'weekly'], true)
            ? (string) $request->getBodyParam('aiVisibilityCadence')
            : 'off';
        $settings->aiVisibilityCompetitorDomains = Strings::splitLines((string) $request->getBodyParam('aiVisibilityCompetitorDomains', ''));
        $maxPerRun = (int) $request->getBodyParam('aiVisibilityMaxPerRun', 50);
        $settings->aiVisibilityMaxPerRun = max(1, min(1000, $maxPerRun));

        Plugin::$plugin->settings->save($settings);
        Craft::$app->getSession()->setNotice(Craft::t('beacon', 'aiVisibility.flash.settings.saved'));
        return $this->redirect(self::REDIRECT);
    }

    public function actionRunNow(): ?Response
    {
        $this->requirePostRequest();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        if (!Plugin::$plugin->aiVisibility->isActive()) {
            Craft::$app->getSession()->setError(Craft::t('beacon', 'aiVisibility.flash.inactive'));
            return $this->redirect(self::REDIRECT);
        }

        Craft::$app->getQueue()->push(new CheckAiVisibilityJob(['siteId' => $siteId]));
        Craft::$app->getSession()->setNotice(Craft::t('beacon', 'aiVisibility.flash.run.queued'));
        return $this->redirect(self::REDIRECT);
    }
}
