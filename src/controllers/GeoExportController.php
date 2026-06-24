<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\MarkdownResponse;
use anvildev\beacon\helpers\SeoFieldReader;
use anvildev\beacon\integrations\CommerceIntegration;
use anvildev\beacon\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\TooManyRequestsHttpException;

class GeoExportController extends Controller
{
    public array|int|bool $allowAnonymous = true;

    /**
     * `GET /geo/export?id=123` — Markdown export for a live entry.
     *
     * Noindex entries return 404, not 403: Markdown is a representation choice,
     * not an access boundary, so 403 would leak entry existence. Serves
     * pre-generated Markdown from {{%beacon_geo_markdown}} when present;
     * otherwise generates on demand and writes through.
     *
     * @throws NotFoundHttpException
     * @throws TooManyRequestsHttpException
     */
    public function actionIndex(int $id): Response
    {
        Plugin::$plugin->geoExportThrottle->enforce('geo_export_route');
        return $this->buildResponse($this->findExportable('id', $id));
    }

    /**
     * `GET /<entry-uri>.md` — Markdown export resolved by entry URI.
     *
     * Only registered when `geoMarkdownMdSuffixEnabled` is on (see Plugin::init).
     * URI-collision rule: an entry whose URI literally ends in `.md` will be
     * matched by Craft's element resolution first and render normally; this
     * action only fires when no entry exists at the literal `.md` URI.
     *
     * @throws NotFoundHttpException
     * @throws TooManyRequestsHttpException
     */
    public function actionMd(string $uri): Response
    {
        Plugin::$plugin->geoExportThrottle->enforce('geo_export_md');
        $settings = Plugin::$plugin->settings->get();
        if (!$settings->geoMarkdownEnabled || !$settings->geoMarkdownMdSuffixEnabled) {
            throw new NotFoundHttpException();
        }
        return $this->buildResponse($this->findExportable('uri', $uri));
    }

    private function buildResponse(?ElementInterface $element): Response
    {
        if ($element === null || SeoFieldReader::isNoIndexFor($element) || ($markdown = $this->resolveMarkdown($element)) === null) {
            throw new NotFoundHttpException();
        }
        $canonical = $element->getUrl();
        $response = MarkdownResponse::build($markdown, is_string($canonical) ? $canonical : null, $element->dateUpdated);
        $response->headers->set('X-Token-Estimate', (string) Plugin::$plugin->tokenEstimator->estimate($markdown));
        return $response;
    }

    private function findExportable(string $by, int|string $value): ?ElementInterface
    {
        return CommerceIntegration::findLiveMarkdownElement($by, $value, Craft::$app->getSites()->getCurrentSite()->id);
    }

    /**
     * Pre-generated row first, on-demand fallback (write-through) second.
     */
    private function resolveMarkdown(ElementInterface $element): ?string
    {
        $store = Plugin::$plugin->geoMarkdownStore;
        $siteId = (int) $element->siteId;
        $elementId = (int) $element->id;

        $row = $store->find($siteId, $elementId);
        if ($row !== null && is_string($row['markdown']) && $row['markdown'] !== '') {
            $store->touchRequested($siteId, $elementId);
            return $row['markdown'];
        }

        $markdown = Plugin::$plugin->geoMarkdownExport->exportElement($element);
        if ($markdown === null) {
            return null;
        }
        $store->put($siteId, $elementId, $markdown);
        return $markdown;
    }
}
