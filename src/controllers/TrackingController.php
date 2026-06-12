<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\enums\TrackingProvider;
use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\models\TrackingScript;
use anvildev\beacon\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * @phpstan-import-type SiteOverrides from \anvildev\beacon\services\SiteOverrideResolver
 */
class TrackingController extends Controller
{
    use BeaconCpPermissionTrait;
    use RetainSubmittedFormTrait;

    protected const BEACON_PERMISSION = BeaconPermissions::EDIT_TRACKING;

    /**
     * Lists the configured tracking scripts and the available providers.
     */
    public function actionIndex(): Response
    {
        $plugin = Plugin::$plugin;
        return $this->renderTemplate('beacon/tracking/_index', [
            'scripts' => $plugin->tracking->list(),
            'providers' => $plugin->trackingRegistry->all(),
        ]);
    }

    /**
     * Renders the tracking-script edit form (or a blank one for a new script).
     *
     * @throws \yii\web\NotFoundHttpException when $uid has no matching script, or the script's provider is unknown
     */
    public function actionEdit(?string $uid = null, ?string $provider = null): Response
    {
        $plugin = Plugin::$plugin;

        if ($uid !== null) {
            $record = $plugin->tracking->findByUid($uid) ?? throw new NotFoundHttpException();
            $script = new TrackingScript([
                'uid' => $record->uid,
                'name' => $record->name,
                'provider' => $record->provider,
                'config' => $plugin->tracking->normalizeConfig($record->config),
                'placement' => $record->placement,
                'sortOrder' => (int) $record->sortOrder,
                'siteOverrides' => $plugin->tracking->normalizeOverrides($record->siteOverrides),
            ]);
        } else {
            $script = new TrackingScript(['provider' => $provider ?? TrackingProvider::Ga4->value]);
        }

        $providerImpl = $plugin->trackingRegistry->get($script->provider)
            ?? throw new NotFoundHttpException(Craft::t('beacon', 'flash.tracking.unknown.provider', ['provider' => $script->provider]));

        return $this->renderTemplate('beacon/tracking/_edit', [
            'script' => $script,
            'provider' => $providerImpl,
            'sites' => Craft::$app->getSites()->getAllSites(),
        ]);
    }

    /**
     * Creates or updates a tracking script from the posted form, validating the
     * provider config, and redirects to the posted return URL on success.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     * @throws \yii\web\ForbiddenHttpException when the user is not an admin
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();
        $request = Http::request();
        $plugin = Plugin::$plugin;
        $session = Craft::$app->getSession();

        $script = new TrackingScript([
            'uid' => $request->getBodyParam('uid'),
            'name' => (string) $request->getBodyParam('name', ''),
            'provider' => (string) $request->getBodyParam('provider', 'custom'),
            'config' => $request->getBodyParam('config') ?? [],
            'placement' => (string) $request->getBodyParam('placement', 'head'),
            'sortOrder' => (int) $request->getBodyParam('sortOrder', 0),
            'siteOverrides' => $this->normalizeSiteOverrides($request->getBodyParam('siteOverrides')),
        ]);

        $providerImpl = $plugin->trackingRegistry->get($script->provider);
        if (!$providerImpl) {
            $session->setError(Craft::t('beacon', 'flash.tracking.unknown.provider.2'));
            return null;
        }

        foreach ($providerImpl->validateConfig($script->config) as $field => $msg) {
            $script->addError("config.{$field}", $msg);
        }

        if (!$plugin->tracking->saveScript($script) || $script->hasErrors()) {
            $session->setError(Craft::t('beacon', 'flash.tracking.couldnt.save'));
            return $this->retainSubmittedForm(['script' => $script]);
        }

        $session->setNotice(Craft::t('beacon', 'flash.tracking.tracking.script.saved'));
        return $this->redirectToPostedUrl();
    }

    /**
     * Deletes the tracking script identified by the posted `uid`, returning JSON
     * or redirecting depending on the request's Accept header.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     * @throws \yii\web\ForbiddenHttpException when the user is not an admin
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();
        $request = Http::request();
        Plugin::$plugin->tracking->deleteScript($request->getRequiredBodyParam('uid'));

        if ($request->getAcceptsJson()) {
            return $this->asSuccess(Craft::t('beacon', 'flash.tracking.tracking.script.deleted'));
        }
        Craft::$app->getSession()->setNotice(Craft::t('beacon', 'flash.tracking.tracking.script.deleted'));
        return $this->redirectToPostedUrl();
    }

    /**
     * Coerces the raw `siteOverrides` POST payload into the shape expected by
     * {@see TrackingScript::validateSiteOverrides()}. Empty / unchecked rows
     * are dropped so that submitting the form with no overrides results in
     * a `null` value rather than a row of empty strings.
     *
     * @return SiteOverrides|null
     */
    private function normalizeSiteOverrides(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $normalized = [];
        foreach ($raw as $siteUid => $entry) {
            if (!is_string($siteUid) || $siteUid === '' || !is_array($entry)) {
                continue;
            }
            $row = [];
            if (array_key_exists('enabled', $entry)) {
                $enabled = match ($entry['enabled']) {
                    '0', 0, false, 'false' => false,
                    '1', 1, true, 'true' => true,
                    default => null,
                };
                if ($enabled !== null) {
                    $row['enabled'] = $enabled;
                }
            }
            if (isset($entry['config']) && is_array($entry['config'])) {
                $row['config'] = $entry['config'];
            }
            if ($row !== []) {
                $normalized[$siteUid] = $row;
            }
        }
        return $normalized !== [] ? $normalized : null;
    }
}
