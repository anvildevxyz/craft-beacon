<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\models\LinkSettings;
use anvildev\beacon\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * Edit screen for the Links (internal-link-graph) feature settings, persisted
 * via {@see \anvildev\beacon\services\Links::saveSettings()} (a single
 * `beacon_link_settings` row).
 *
 * Gated behind {@see BeaconPermissions::EDIT_LINKS}.
 *
 * @author Anvil
 * @since 1.0.0
 */
class LinkSettingsController extends Controller
{
    // =========================================================================
    // Traits
    // =========================================================================

    use BeaconCpPermissionTrait;

    // =========================================================================
    // Const Properties
    // =========================================================================

    protected const BEACON_PERMISSION = BeaconPermissions::EDIT_LINKS;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Renders the Links settings form.
     */
    public function actionIndex(): Response
    {
        return $this->renderTemplate('beacon/links/settings', [
            'settings' => Plugin::$plugin->links->getSettings(),
            'sections' => Craft::$app->getEntries()->getAllSections(),
        ]);
    }

    /**
     * Populates a {@see LinkSettings} from POST and saves it; re-renders the
     * form on validation failure.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $request = Http::request();
        $links = Plugin::$plugin->links;
        $settings = $links->getSettings();

        $enabledSections = $request->getBodyParam('enabledSections', []);
        $settings->enabledSections = is_array($enabledSections) ? array_values(array_filter($enabledSections, 'is_string')) : [];
        $settings->maxKeywordsPerEntry = (int) $request->getBodyParam('maxKeywordsPerEntry', 50);
        $settings->stopWordsLanguage = (string) $request->getBodyParam('stopWordsLanguage', 'en');
        $settings->minKeywordLength = (int) $request->getBodyParam('minKeywordLength', 3);
        $settings->indexOnSave = (bool) $request->getBodyParam('indexOnSave', true);
        $settings->showSidebarSuggestions = (bool) $request->getBodyParam('showSidebarSuggestions', true);
        $settings->maxSuggestions = (int) $request->getBodyParam('maxSuggestions', 10);
        $settings->minScore = (float) $request->getBodyParam('minScore', 0.1);
        $settings->maxDocumentFrequencyRatio = (float) $request->getBodyParam('maxDocumentFrequencyRatio', 0.6);
        $settings->excludeSameSection = (bool) $request->getBodyParam('excludeSameSection', false);
        $settings->embeddingsEnabled = (bool) $request->getBodyParam('embeddingsEnabled', false);
        $settings->embeddingsBaseUrl = (string) $request->getBodyParam('embeddingsBaseUrl', '');
        $postedKey = (string) $request->getBodyParam('embeddingsApiKey', '');
        $settings->embeddingsApiKey = $postedKey !== '' ? $postedKey : $settings->embeddingsApiKey;
        $settings->embeddingsModel = (string) $request->getBodyParam('embeddingsModel', 'text-embedding-3-small');
        $settings->reportCacheDuration = (int) $request->getBodyParam('reportCacheDuration', 3600);
        $settings->autoReindexInterval = (int) $request->getBodyParam('autoReindexInterval', 0);
        $settings->httpAuditTimeout = (int) $request->getBodyParam('httpAuditTimeout', 10);
        $settings->httpAuditDelay = (int) $request->getBodyParam('httpAuditDelay', 200);

        $raw = $request->getBodyParam('genericAnchorPatterns', '');
        if (is_string($raw)) {
            $settings->genericAnchorPatterns = array_values(array_filter(
                array_map('trim', preg_split('/\R/', $raw) ?: []),
                static fn(string $s): bool => $s !== '',
            ));
        }

        if (!$links->saveSettings($settings)) {
            Craft::$app->getSession()->setError(Craft::t('beacon', 'flash.links.couldnt.save.settings'));
            return $this->renderTemplate('beacon/links/settings', [
                'settings' => $settings,
                'sections' => Craft::$app->getEntries()->getAllSections(),
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('beacon', 'flash.links.settings.saved'));
        return $this->redirectToPostedUrl();
    }
}
