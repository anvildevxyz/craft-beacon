<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\elements\RedirectElement;
use anvildev\beacon\enums\RedirectQueryStringMode;
use anvildev\beacon\enums\RedirectSource;
use anvildev\beacon\enums\RedirectStatusCode;
use anvildev\beacon\enums\RedirectType;
use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\models\RedirectListFilters;
use anvildev\beacon\Plugin;
use Craft;
use craft\enums\PropagationMethod;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UploadedFile;

class RedirectsController extends Controller
{
    use BeaconCpPermissionTrait;
    use RetainSubmittedFormTrait;
    use SiteScopedCpControllerTrait;

    protected const BEACON_PERMISSION = BeaconPermissions::EDIT_REDIRECTS;

    /** Upper bound on the CSV upload read into memory by {@see actionImport()}. */
    private const MAX_IMPORT_BYTES = 5 * 1024 * 1024;

    /**
     * Renders the native Craft element index for redirects (drag-reorderable,
     * backed by the precedence structure).
     */
    public function actionIndex(): Response
    {
        Plugin::$plugin->redirects->ensureStructure();

        return $this->renderTemplate('beacon/redirects/_index');
    }

    /**
     * Renders the redirect edit form (or a pre-filled one for a new redirect).
     *
     * @throws NotFoundHttpException when $redirectId is given but no matching redirect exists
     */
    public function actionEdit(?int $redirectId = null, ?RedirectElement $redirect = null): Response
    {
        $request = Http::request();
        $site = $this->resolveSite();

        // $redirect may be injected by actionSave() on a failed save (via
        // setRouteParams) so the form re-renders carrying validation errors.
        if ($redirect === null) {
            if ($redirectId !== null) {
                $redirect = Plugin::$plugin->redirects->findById($redirectId);
                if ($redirect === null) {
                    throw new NotFoundHttpException();
                }
            } else {
                $redirect = new RedirectElement();
                $redirect->siteId = $site->id;
                $redirect->propagationMethod = PropagationMethod::All;
                $redirect->statusCode = RedirectStatusCode::MovedPermanently->value;
                $redirect->type = RedirectType::Exact->value;
                $redirect->queryStringMode = RedirectQueryStringMode::Ignore->value;
                $redirect->enabled = true;
                $redirect->sourceUri = mb_substr(trim((string) $request->getQueryParam('prefillSource', '')), 0, 255) ?: null;
                $redirect->targetUri = mb_substr(trim((string) $request->getQueryParam('prefillTarget', '')), 0, 500) ?: null;
            }
        }

        return $this->renderTemplate('beacon/redirects/_edit', [
            'redirect' => $redirect,
            'isNew' => !$redirect->id,
            'currentSite' => $site,
            'from404' => (int) $request->getQueryParam('from404', 0),
        ]);
    }

    /**
     * Creates or updates a redirect element from the posted form. When created
     * from a 404 log entry, marks that entry handled before redirecting.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $request = Http::request();
        $session = Craft::$app->getSession();

        $id = $request->getBodyParam('redirectId');
        $redirect = ($id ? Plugin::$plugin->redirects->findById((int) $id) : null) ?? new RedirectElement();

        // The element is anchored to a site; the propagation method then decides
        // which sites it's live on (none = this site only, all = every site).
        $siteId = (int) $request->getBodyParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id;
        $this->requireEditableSite($siteId);
        $redirect->siteId = $siteId;
        $redirect->propagationMethod = PropagationMethod::tryFrom(
            (string) $request->getBodyParam('propagationMethod', 'all'),
        ) ?? PropagationMethod::All;

        $redirect->sourceUri = trim((string) $request->getBodyParam('sourceUri'));
        $redirect->targetUri = trim((string) $request->getBodyParam('targetUri'));
        $redirect->statusCode = (int) $request->getBodyParam('statusCode', RedirectStatusCode::MovedPermanently->value);
        $redirect->type = (string) $request->getBodyParam('type', RedirectType::Exact->value);
        $redirect->enabled = (bool) $request->getBodyParam('enabled', true);
        $redirect->note = trim((string) $request->getBodyParam('note', '')) ?: null;
        $redirect->queryStringMode = RedirectQueryStringMode::tryFrom((string) $request->getBodyParam('queryStringMode', 'ignore'))?->value ?? 'ignore';
        $redirect->source = $redirect->source ?: RedirectSource::Manual->value;
        if (!$redirect->id) {
            $redirect->sortOrder = Plugin::$plugin->redirects->nextSortOrder();
        }

        if (!Craft::$app->getElements()->saveElement($redirect)) {
            $session->setError(Craft::t('beacon', 'flash.redirects.couldnt.save'));
            return $this->retainSubmittedForm(['redirect' => $redirect]);
        }

        $from404 = (int) $request->getBodyParam('from404', 0);
        if ($from404 > 0) {
            Plugin::$plugin->redirect404Log->markHandled($from404, (int) $redirect->siteId);
        }

        $session->setNotice(Craft::t('beacon', 'flash.redirects.redirect.saved'));
        return $this->redirectToPostedUrl($redirect);
    }

    /**
     * Imports redirects from an uploaded CSV file into the selected site.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     */
    public function actionImport(): ?Response
    {
        $this->requirePostRequest();
        $session = Craft::$app->getSession();
        $sites = Craft::$app->getSites();

        $file = UploadedFile::getInstanceByName('csvFile');
        if ($file === null) {
            $session->setError(Craft::t('beacon', 'flash.redirects.no.file.uploaded'));
            return null;
        }

        if (strtolower($file->getExtension()) !== 'csv') {
            $session->setError(Craft::t('beacon', 'flash.redirects.uploaded.file.must.csv.file'));
            return null;
        }

        if ($file->size > self::MAX_IMPORT_BYTES) {
            $session->setError(Craft::t('beacon', 'flash.redirects.uploaded.file.too.large.max', [
                'max' => self::MAX_IMPORT_BYTES / 1024 / 1024,
            ]));
            return null;
        }

        $content = file_get_contents($file->tempName);
        if ($content === false) {
            $session->setError(Craft::t('beacon', 'flash.redirects.couldnt.read.upload'));
            return null;
        }

        $siteId = (int) Http::request()->getBodyParam('siteId', $sites->getCurrentSite()->id);
        $this->requireEditableSite($siteId);
        $result = Plugin::$plugin->redirectImporter->importFromCsv($content, $siteId);

        $session->setNotice(Craft::t('beacon', 'flash.redirects.redirects.imported.rows.skipped', [
            'inserted' => $result->insertedCount,
            'skipped' => $result->skippedCount,
        ]));
        if ($result->errors !== []) {
            $session->set('beacon.import.errors', $result->errors);
        }

        return $this->redirect(UrlHelper::cpUrl('beacon/redirects', [
            'site' => $sites->getSiteById($siteId)?->handle ?? $sites->getCurrentSite()->handle,
        ]));
    }

    /**
     * Renders the redirect CSV import form.
     */
    public function actionImportForm(): Response
    {
        return $this->renderTemplate('beacon/redirects/_import');
    }

    /**
     * Exports the current site's filtered redirects as a downloadable CSV file.
     */
    public function actionExport(): Response
    {
        $request = Http::request();
        $plugin = Plugin::$plugin;
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $this->requireEditableSite($siteId);
        $thresholdDays = $plugin->settings->get()->staleThresholdDays;
        $filters = RedirectListFilters::fromQueryParams($request->getQueryParams());

        $content = $plugin->redirectImporter->exportToCsv(
            $plugin->redirects->listForSiteFiltered($siteId, $filters, $thresholdDays),
        );

        $response = Http::response();
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="beacon-redirects-' . date('Y-m-d') . '.csv"');
        $response->headers->set('Cache-Control', 'no-store');
        $response->content = $content;
        return $response;
    }
}
