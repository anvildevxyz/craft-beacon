<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\Http;
use anvildev\beacon\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * AJAX endpoint behind the Beacon SEO field's entity picker. The field's
 * search box calls `actions/beacon/entities/search`; results feed the
 * typeahead so editors can bind a page to Wikidata entities.
 *
 * CP-only and login-gated (any user who can reach the entry editor can search
 * the public Wikidata API). The search itself touches no Beacon data, so no
 * element-level permission is required.
 */
class EntitiesController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function actionSearch(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();

        $request = Http::request();
        $query = (string) $request->getParam('q', '');
        $language = (string) $request->getParam('language', Craft::$app->language);

        $results = Plugin::$plugin->wikidata->search($query, $language);

        return $this->asJson(['results' => $results]);
    }
}
