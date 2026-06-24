<?php

namespace anvildev\beacon\helpers;

use Craft;

/**
 * Permission keys for Beacon CP screens. Coarse-by-area: each top-level
 * subnav item maps to a single permission. Granular per-tab gating is
 * deliberately avoided.
 *
 * Registration happens once in {@see \anvildev\beacon\Plugin::init()} via
 * `UserPermissions::EVENT_REGISTER_PERMISSIONS`.
 */
final class BeaconPermissions
{
    public const VIEW_DASHBOARD = 'beacon:viewDashboard';
    public const EDIT_AUTHORS = 'beacon:editAuthors';
    public const EDIT_REDIRECTS = 'beacon:editRedirects';
    public const EDIT_SHORT_LINKS = 'beacon:editShortLinks';
    public const EDIT_SCHEMAS = 'beacon:editSchemas';
    public const EDIT_SITEMAP = 'beacon:editSitemap';
    public const EDIT_TRACKING = 'beacon:editTracking';
    public const EDIT_CRAWLERS = 'beacon:editCrawlers';
    public const EDIT_SETTINGS = 'beacon:editSettings';
    public const EDIT_GEO_SCORE = 'beacon:editGeoScore';
    public const USE_AI_GENERATION = 'beacon:useAiGeneration';
    public const EDIT_AI_VISIBILITY = 'beacon:editAiVisibility';

    /**
     * Definitions registered with Craft. Keep order = display order.
     *
     * @return array<string,array{label:string}>
     */
    public static function definitions(): array
    {
        $t = static fn(string $m): string => Craft::t('beacon', $m);
        return [
            self::VIEW_DASHBOARD => ['label' => $t('View Beacon dashboard')],
            self::EDIT_AUTHORS => ['label' => $t('Edit authors')],
            self::EDIT_REDIRECTS => ['label' => $t('Edit redirects')],
            self::EDIT_SHORT_LINKS => ['label' => $t('Edit short links')],
            self::EDIT_SCHEMAS => ['label' => $t('Edit schemas')],
            self::EDIT_SITEMAP => ['label' => $t('Edit sitemap')],
            self::EDIT_TRACKING => ['label' => $t('Edit tracking')],
            self::EDIT_CRAWLERS => ['label' => $t('Edit crawler settings (AI crawlers, llms.txt, robots.txt, humans.txt, ads.txt)')],
            self::EDIT_SETTINGS => ['label' => $t('Edit Beacon settings')],
            self::EDIT_GEO_SCORE => ['label' => $t('Manually recompute GEO scores (drill-down recompute button)')],
            self::USE_AI_GENERATION => ['label' => $t('Use AI content generation (Generate buttons in the SEO field)')],
            self::EDIT_AI_VISIBILITY => ['label' => $t('Manage AI visibility tracking (benchmark prompts, runs)')],
        ];
    }

    public static function userCan(string $permission): bool
    {
        return (bool) Craft::$app->getUser()->getIdentity()?->can($permission);
    }
}
