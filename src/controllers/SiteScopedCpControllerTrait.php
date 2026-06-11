<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\enums\RenderCacheType;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\Plugin;
use Craft;
use craft\models\Site;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * @phpstan-import-type LabelValueOption from \anvildev\beacon\types\ArrayShapes
 *
 * Resolves the active site for per-site CP settings pages.
 *
 * Reads `?site=<handle>` from the query string, falls back to the current site.
 * Pairs with {@see redirectAfterSiteSave()} for the post-save round-trip.
 */
trait SiteScopedCpControllerTrait
{
    private function resolveSite(): Site
    {
        $sites = Craft::$app->getSites();
        $handle = Http::request()->getQueryParam('site');
        return (is_string($handle) && $handle !== '' ? $sites->getSiteByHandle($handle) : null)
            ?? $sites->getCurrentSite();
    }

    /**
     * Reads `siteId` from the POST body (the hidden field that the site picker
     * on settings forms posts back), falling back to the current site when the
     * value is missing or non-positive. Always verifies the current user is
     * allowed to edit the resolved site, so a per-site editor can't tamper
     * with the hidden field to write settings for a site they don't manage.
     */
    private function resolveSiteIdFromPost(): int
    {
        $posted = (int) Http::request()->getBodyParam('siteId');
        $siteId = $posted > 0 ? $posted : Craft::$app->getSites()->getCurrentSite()->id;
        $this->requireEditableSite($siteId);
        return $siteId;
    }

    /**
     * Bail with 403 unless the current user has CP edit access to $siteId.
     * Controllers using this trait already gate on a global permission via
     * {@see BeaconCpPermissionTrait}; this blocks a user with that permission
     * on site A from posting settings scoped to site B.
     */
    private function requireEditableSite(int $siteId): void
    {
        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null) {
            throw new ForbiddenHttpException();
        }
        if ($user->admin) {
            return;
        }
        $site = Craft::$app->getSites()->getSiteById($siteId);
        if ($site === null || !$user->can('editSite:' . $site->uid)) {
            throw new ForbiddenHttpException();
        }
    }

    /**
     * Redirects to a CP page with `?site=<handle>` so the saved-site context
     * survives the round-trip.
     */
    private function redirectAfterSiteSave(string $cpUrl, int $siteId): Response
    {
        $handle = Craft::$app->getSites()->getSiteById($siteId)?->handle ?? '';
        return $this->redirect("{$cpUrl}?site={$handle}");
    }

    /**
     * Invalidates a per-site render cache, flashes a notice, and redirects
     * back to the settings screen with the saved-site query param.
     */
    private function finishSiteScopedSave(
        string $notice,
        string $cpUrl,
        int $siteId,
        RenderCacheType $cacheType,
    ): Response {
        Plugin::$plugin->renderCache->invalidate($siteId, $cacheType, null);
        Craft::$app->getSession()->setNotice($notice);
        return $this->redirectAfterSiteSave($cpUrl, $siteId);
    }

    /**
     * Lists URL-having scopes that can appear in per-site sitemap / llms.txt
     * configurations. Returns all Entry sections, plus a synthetic
     * `__products__` row when Commerce is installed — products are just
     * another bucket of public URLs that follow the same priority /
     * changefreq / inclusion rules as sections.
     *
     * @return list<LabelValueOption>
     */
    private function collectSections(): array
    {
        $rows = array_map(
            static fn($s) => ['label' => (string) $s->name, 'value' => (string) $s->handle],
            array_values(Craft::$app->getEntries()->getAllSections()),
        );
        if (class_exists(\craft\commerce\elements\Product::class)) {
            $rows[] = ['label' => Craft::t('beacon', 'Products (Commerce)'), 'value' => '__products__'];
        }
        return $rows;
    }

    /**
     * @param array<mixed>|string|null $raw
     * @return list<string>
     */
    private function normalizeStringArray(array|string|null $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_filter(
            array_map(static fn($v) => is_string($v) ? trim($v) : '', $raw),
            static fn(string $v) => $v !== '',
        ));
    }
}
