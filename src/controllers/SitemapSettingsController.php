<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\enums\RenderCacheType;
use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\helpers\Strings;
use anvildev\beacon\models\SitemapSettings;
use anvildev\beacon\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * @phpstan-import-type SectionSitemapOverride from \anvildev\beacon\models\SitemapSettings
 */
class SitemapSettingsController extends Controller
{
    use BeaconCpPermissionTrait;
    use SiteScopedCpControllerTrait;

    protected const BEACON_PERMISSION = BeaconPermissions::EDIT_SITEMAP;

    /** @var array<string, true>|null */
    private ?array $validHandlesMapMemo = null;

    /**
     * Renders the sitemap settings form for the current (or selected) site.
     */
    public function actionIndex(): Response
    {
        $site = $this->resolveSite();
        return $this->renderTemplate('beacon/sitemap', [
            'site' => $site,
            'settings' => Plugin::$plugin->siteSettings->getSitemap($site->id),
            'allSections' => $this->collectSections(),
            'sites' => Craft::$app->getSites()->getAllSites(),
        ]);
    }

    /**
     * Persists the posted sitemap settings for a site, invalidates the sitemap
     * cache, and redirects back to the settings screen.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $request = Http::request();
        $plugin = Plugin::$plugin;
        $siteId = $this->resolveSiteIdFromPost();
        $changefreq = (string) $request->getBodyParam('changefreq', 'weekly');

        $plugin->siteSettings->saveSitemap(new SitemapSettings(
            siteId: $siteId,
            sections: $this->normalizeStringArray($request->getBodyParam('sections', [])),
            excludeSections: $this->normalizeStringArray($request->getBodyParam('excludeSections', [])),
            priority: max(0.0, min(1.0, (float) $request->getBodyParam('priority', 0.8))),
            changefreq: SitemapSettings::isValidChangefreq($changefreq) ? $changefreq : 'weekly',
            newsSections: $this->normalizeStringArray($request->getBodyParam('newsSections', [])),
            sectionSitemap: $this->normalizeSectionSitemapOverrides($request->getBodyParam('sectionSitemap')),
            geoMarkdownFrontMatter: $this->normalizeSectionFrontMatterOverrides($request->getBodyParam('geoMarkdownFrontMatter')),
        ));

        return $this->finishSiteScopedSave(
            Craft::t('beacon', 'flash.sitemap.sitemap.settings.saved'),
            'beacon/sitemap',
            $siteId,
            RenderCacheType::Sitemap,
        );
    }

    /**
     * @return array<string, true>
     */
    private function validHandlesMap(): array
    {
        return $this->validHandlesMapMemo ??= array_fill_keys(
            array_column(Craft::$app->getEntries()->getAllSections(), 'handle'),
            true,
        );
    }

    /**
     * @param array<mixed, mixed>|string|null $raw
     * @return array<string, SectionSitemapOverride>
     */
    private function normalizeSectionSitemapOverrides(array|string|null $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $valid = $this->validHandlesMap();
        $out = [];
        foreach ($raw as $handle => $row) {
            if (!is_string($handle) || $handle === '' || !isset($valid[$handle]) || !is_array($row)) {
                continue;
            }
            $part = [];
            $p = $row['priority'] ?? null;
            if ($p !== null && $p !== '' && is_numeric($p)) {
                $part['priority'] = max(0.0, min(1.0, (float) $p));
            }
            $c = isset($row['changefreq']) ? (string) $row['changefreq'] : '';
            if ($c !== '' && SitemapSettings::isValidChangefreq($c)) {
                $part['changefreq'] = $c;
            }
            if ($part !== []) {
                $out[$handle] = $part;
            }
        }
        return $out;
    }

    /**
     * Each `geoMarkdownFrontMatter[<handle>]` body is a textarea of `key: value`
     * lines. Empty bodies are dropped. Unknown handles are dropped. Quotes
     * around values are stripped if balanced.
     *
     * @param array<mixed, mixed>|string|null $raw
     * @return array<string, array<string, string>>
     */
    private function normalizeSectionFrontMatterOverrides(array|string|null $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $valid = $this->validHandlesMap();
        $out = [];
        foreach ($raw as $handle => $body) {
            if (!is_string($handle) || $handle === '' || !isset($valid[$handle]) || !is_string($body)) {
                continue;
            }
            if (($entries = Strings::parseKeyValueLines($body)) !== []) {
                $out[$handle] = $entries;
            }
        }
        return $out;
    }
}
