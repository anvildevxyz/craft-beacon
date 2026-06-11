<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\Plugin;
use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Surfaces unhandled 404s with suggested redirect targets. Lives under the
 * Redirects sub-nav; relies on the same EDIT_REDIRECTS permission.
 *
 * @phpstan-import-type Redirect404LogRow from \anvildev\beacon\types\ArrayShapes
 * @phpstan-import-type Redirect404WithSuggestions from \anvildev\beacon\types\ArrayShapes
 */
class Redirect404sController extends Controller
{
    use BeaconCpPermissionTrait;
    use SiteScopedCpControllerTrait;

    protected const BEACON_PERMISSION = BeaconPermissions::EDIT_REDIRECTS;

    /**
     * Lists the top unhandled 404s for the current site, each annotated with
     * suggested redirect targets.
     */
    public function actionIndex(): Response
    {
        $plugin = Plugin::$plugin;
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $rows = $plugin->redirect404Log->topUnhandled($siteId, 100);
        /** @var list<Redirect404WithSuggestions> $withSuggestions */
        $withSuggestions = array_map(
            /** @param Redirect404LogRow $row */
            static fn(array $row): array => [...$row, 'suggestions' => $plugin->redirectSuggestions->suggestFor($row['uri'], $siteId, 3)],
            $rows,
        );

        return $this->renderTemplate('beacon/redirects/404s', [
            'rows' => $withSuggestions,
            'log404sEnabled' => $plugin->settings->get()->log404s,
        ]);
    }

    /**
     * Bulk-mark selected 404 entries as handled (hides them from the list
     * without creating a redirect — for "expected" 404s like deleted spam
     * pages we don't want re-indexed).
     *
     * @throws \yii\web\NotFoundHttpException when the requested bulk action is unknown
     */
    public function actionBulk(): Response
    {
        $this->requirePostRequest();
        $request = Http::request();
        $session = Craft::$app->getSession();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $this->requireEditableSite($siteId);

        /** @var list<int|string> $raw */
        $raw = (array) $request->getRequiredBodyParam('ids');
        $ids = array_values(array_filter(array_map(intval(...), $raw)));

        if ($ids === []) {
            $session->setError(Craft::t('beacon', 'Select at least one 404 entry.'));
            return $this->redirect(UrlHelper::cpUrl('beacon/redirects/404s'));
        }

        $action = (string) $request->getRequiredBodyParam('bulkAction');
        if ($action !== 'markHandled') {
            throw new NotFoundHttpException();
        }

        $count = Plugin::$plugin->redirect404Log->bulkMarkHandled($ids, $siteId);
        $session->setNotice(Craft::t('beacon', '{count} entries marked handled.', ['count' => $count]));
        return $this->redirect(UrlHelper::cpUrl('beacon/redirects/404s'));
    }
}
