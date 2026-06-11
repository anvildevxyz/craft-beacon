<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\jobs\RecomputeGeoScoreJob;
use anvildev\beacon\Plugin;
use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use yii\base\Action;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Drill-down + manual-recompute endpoints for the dashboard widget and
 * the SEO-field chip. Read-only browsing requires `beacon:viewDashboard`
 * (the same permission the widget itself needs); recompute requires
 * `beacon:editGeoScore` (a separate permission so operators can grant
 * read-only triage access without the ability to spin up jobs).
 *
 * Routes registered in {@see \anvildev\beacon\Plugin::registerCpRoutes()}:
 *   GET  beacon/geo-score/drill-down    → actionDrillDown
 *   POST beacon/geo-score/recompute     → actionRecompute
 */
class GeoScoreController extends Controller
{
    use PostJsonTrait;

    /**
     * Per-action permission gating. The drill-down panel is read-only and
     * gated by the dashboard permission; recompute mutates state via the
     * queue and needs the dedicated edit permission.
     *
     * Map extracted as a public static so unit tests can assert the
     * gating decisions without spinning up a full Craft web request.
     */
    public static function requiredPermissionFor(string $actionId): string
    {
        return match ($actionId) {
            'recompute' => BeaconPermissions::EDIT_GEO_SCORE,
            default => BeaconPermissions::VIEW_DASHBOARD,
        };
    }

    /**
     * @param Action<Controller> $action
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission(self::requiredPermissionFor($action->id));
        return true;
    }

    /**
     * Read-only drill-down panel for a single (element, site) score.
     * Returns the rendered CP template; the widget links here when an
     * editor clicks a row in the "worst entries" tile, and the SEO field
     * chip's tooltip links here for the full breakdown.
     *
     * @throws \yii\web\NotFoundHttpException when no score exists or can be computed for the entry
     */
    public function actionDrillDown(int $elementId, int $siteId): Response
    {
        /** @var Entry|null $element */
        $element = Entry::find()->id($elementId)->siteId($siteId)->status(null)->one();

        $score = Plugin::$plugin->geoScore->forElement($elementId, $siteId)
            ?? ($element !== null ? Plugin::$plugin->geoScore->compute($element, $siteId, persist: false) : null);

        if ($score === null) {
            throw new NotFoundHttpException(Craft::t('beacon', 'No GEO score exists for this entry yet. Save the entry to trigger a computation.'));
        }

        return $this->renderTemplate('beacon/_geo-score/drill-down', [
            'score' => $score,
            'element' => $element,
            'elementId' => $elementId,
            'siteId' => $siteId,
            'canRecompute' => BeaconPermissions::userCan(BeaconPermissions::EDIT_GEO_SCORE),
        ]);
    }

    /**
     * Lightweight JSON poll for the SEO-field chip. Recompute runs async on
     * the queue, but the chip is rendered server-side once at page load, so
     * a fresh save leaves it stuck on "Computes on next save" until the next
     * full reload. The field's JS polls this endpoint after a save and swaps
     * the chip in place once the job has persisted a row.
     *
     * Returns `{ready:false}` while the score is still pending, or
     * `{ready:true, html:'…'}` with the re-rendered chip markup once it lands.
     *
     * @throws \yii\web\BadRequestHttpException when the request doesn't accept JSON
     */
    public function actionStatus(int $elementId, int $siteId): Response
    {
        $this->requireAcceptsJson();

        if ($elementId <= 0 || $siteId <= 0) {
            return $this->asJson(['ready' => false]);
        }

        $score = Plugin::$plugin->geoScore->forElement($elementId, $siteId);
        if ($score === null) {
            return $this->asJson(['ready' => false]);
        }

        $weakest = $score->weakestPillar();
        $html = $this->getView()->renderTemplate('beacon/_seo-field/_score-chip', [
            'score' => $score,
            'weakestLabel' => $weakest?->label(),
            'elementId' => $elementId,
            'siteId' => $siteId,
        ]);

        return $this->asJson(['ready' => true, 'html' => $html]);
    }

    /**
     * Manually enqueue a recompute. Returns JSON so the drill-down can
     * disable the button and refresh inline without a full page reload.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST or doesn't accept JSON
     */
    public function actionRecompute(): Response
    {
        $this->requirePostJson();

        $request = Http::request();
        $elementId = (int) $request->getRequiredBodyParam('elementId');
        $siteId = (int) $request->getRequiredBodyParam('siteId');
        if ($elementId <= 0 || $siteId <= 0) {
            return $this->asJson(['success' => false, 'message' => Craft::t('beacon', 'Invalid element or site.')]);
        }

        // Drop the cached row so the job recomputes fresh (sourceHash
        // would otherwise short-circuit if no inputs changed).
        Plugin::$plugin->geoScore->invalidate($elementId, $siteId);

        Craft::$app->getQueue()->push(new RecomputeGeoScoreJob([
            'elementId' => $elementId,
            'siteId' => $siteId,
        ]));

        return $this->asJson([
            'success' => true,
            'message' => Craft::t('beacon', 'Recompute queued. Refresh in a few seconds.'),
        ]);
    }
}
