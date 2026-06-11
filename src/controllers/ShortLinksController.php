<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\elements\ShortLinkElement;
use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\Http;
use Craft;
use craft\enums\PropagationMethod;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ShortLinksController extends Controller
{
    use BeaconCpPermissionTrait;
    use RetainSubmittedFormTrait;
    use SiteScopedCpControllerTrait;

    protected const BEACON_PERMISSION = BeaconPermissions::EDIT_SHORT_LINKS;

    /**
     * Renders the native Craft element index for short links (sources, search,
     * site menu, status filters all handled by core).
     */
    public function actionIndex(): Response
    {
        return $this->renderTemplate('beacon/short-links/_index', [
            'title' => Craft::t('beacon', 'Short links'),
        ]);
    }

    /**
     * Renders the short-link edit form for the requested site (or a blank one
     * for a new short link).
     *
     * @throws NotFoundHttpException when $shortLinkId is given but no matching short link exists
     */
    public function actionEdit(?int $shortLinkId = null, ?ShortLinkElement $shortLink = null): Response
    {
        $site = $this->resolveSite();

        // $shortLink is populated by actionSave() on a failed save (via
        // setRouteParams) so the form can re-render carrying validation errors.
        if ($shortLink === null) {
            if ($shortLinkId !== null) {
                $shortLink = $this->findShortLinkById($shortLinkId, $site->id);
                if ($shortLink === null) {
                    throw new NotFoundHttpException();
                }
            } else {
                $shortLink = new ShortLinkElement();
                $shortLink->siteId = $site->id;
                $shortLink->enabled = true;
            }
        }

        return $this->renderTemplate('beacon/short-links/_edit', [
            'shortLink' => $shortLink,
            'isNew' => !$shortLink->id,
            'currentSite' => $site,
            'siteUrl' => rtrim($site->getBaseUrl() ?? '/', '/'),
        ]);
    }

    /**
     * Creates or updates a short link from the posted form and saves it as a
     * Craft element (propagating to its supported sites). Redirects to the
     * posted return URL on success.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $request = Http::request();
        $session = Craft::$app->getSession();

        $id = $request->getBodyParam('shortLinkId');
        $siteId = (int) $request->getBodyParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id;

        if ($id) {
            $shortLink = $this->findShortLinkById((int) $id, $siteId);
            if ($shortLink === null) {
                throw new NotFoundHttpException();
            }
        } else {
            $shortLink = new ShortLinkElement();
            $shortLink->siteId = $siteId;
        }

        $shortLink->slug = trim((string) $request->getBodyParam('slug'));
        $shortLink->destination = trim((string) $request->getBodyParam('destination'));
        $shortLink->statusCode = (int) $request->getBodyParam('statusCode', 302);
        $shortLink->enabled = (bool) $request->getBodyParam('enabled', true);
        $shortLink->propagationMethod = PropagationMethod::tryFrom(
            (string) $request->getBodyParam('propagationMethod', 'all'),
        ) ?? PropagationMethod::All;
        $shortLink->expiresAt = DateTimeHelper::toDateTime($request->getBodyParam('expiresAt')) ?: null;
        $shortLink->note = trim((string) $request->getBodyParam('note', '')) ?: null;

        if (!Craft::$app->getElements()->saveElement($shortLink)) {
            $session->setError(Craft::t('beacon', 'Couldn\'t save short link.'));
            return $this->retainSubmittedForm(['shortLink' => $shortLink]);
        }

        $session->setNotice(Craft::t('beacon', 'Short link saved.'));
        return $this->redirectToPostedUrl($shortLink);
    }

    /**
     * Deletes a short link element.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $id = (int) Http::request()->getBodyParam('shortLinkId');
        $shortLink = ShortLinkElement::find()->id($id)->siteId('*')->status(null)->one();
        if ($shortLink !== null) {
            Craft::$app->getElements()->deleteElement($shortLink);
            Craft::$app->getSession()->setNotice(Craft::t('beacon', 'Short link deleted.'));
        }
        return $this->redirectToPostedUrl();
    }

    private function findShortLinkById(int $id, int $siteId): ?ShortLinkElement
    {
        return ShortLinkElement::find()->id($id)->siteId($siteId)->status(null)->one()
            ?? ShortLinkElement::find()->id($id)->siteId('*')->status(null)->one();
    }
}
