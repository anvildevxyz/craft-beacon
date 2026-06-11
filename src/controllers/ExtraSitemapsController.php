<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\RawResponse;
use anvildev\beacon\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ExtraSitemapsController extends Controller
{
    public array|int|bool $allowAnonymous = true;
    public $enableCsrfValidation = false;

    /**
     * Serves the Google News sitemap XML for the current site.
     *
     * @throws \yii\web\NotFoundHttpException when no news sitemap can be rendered
     */
    public function actionNews(): Response
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        $sitemap = Plugin::$plugin->siteSettings->getSitemap($site->id);

        $xml = Plugin::$plugin->extraSitemaps->renderNews(
            siteId: $site->id,
            newsSectionHandles: $sitemap->newsSections,
            publicationName: (string) $site->name,
            language: $site->language,
        );
        return $this->xml(
            $xml,
            ['beacon-sitemap-news', "beacon-site-{$site->id}"],
        );
    }

    /**
     * Serves the image sitemap XML for the current site's included sections.
     *
     * @throws \yii\web\NotFoundHttpException when no image sitemap can be rendered
     */
    public function actionImages(): Response
    {
        return $this->renderIncluded(
            static fn(int $siteId, array $sections) => Plugin::$plugin->extraSitemaps->renderImage(
                siteId: $siteId,
                sectionHandles: $sections,
            ),
            'beacon-sitemap-images',
        );
    }

    /**
     * Serves the video sitemap XML for the current site's included sections.
     *
     * @throws \yii\web\NotFoundHttpException when no video sitemap can be rendered
     */
    public function actionVideos(): Response
    {
        return $this->renderIncluded(
            static fn(int $siteId, array $sections) => Plugin::$plugin->extraSitemaps->renderVideo(
                siteId: $siteId,
                sectionHandles: $sections,
            ),
            'beacon-sitemap-videos',
        );
    }

    /** @param callable(int, list<string>): ?string $render */
    private function renderIncluded(callable $render, string $kindTag): Response
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        $sitemap = Plugin::$plugin->siteSettings->getSitemap($site->id);
        return $this->xml(
            $render($site->id, $sitemap->includedSectionHandles()),
            [$kindTag, "beacon-site-{$site->id}"],
        );
    }

    /**
     * @param list<string> $cacheTags
     */
    private function xml(?string $xml, array $cacheTags = []): Response
    {
        if ($xml === null) {
            throw new NotFoundHttpException();
        }
        return RawResponse::build('application/xml; charset=UTF-8', $xml, cacheTags: $cacheTags);
    }
}
