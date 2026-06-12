<?php

/**
 * Beacon config example.
 *
 * Beacon is fully CP-driven — every setting has a UI under `/admin/beacon`,
 * and you do NOT need this file for normal use. It exists only for the few
 * options that are deliberately file-only (catalogue size, per-site IndexNow
 * keys) and for locking CP-managed values in code on hardened/multi-env setups.
 *
 * To use it, copy this file to your project's `config/beacon.php`. Every key is
 * optional; omit any you don't need. Values set here OVERRIDE the matching
 * Control Panel setting, so a key listed below becomes read-only in the CP.
 *
 * @see \anvildev\beacon\models\Settings for the full property list + defaults.
 */

return [
    // --- Behaviour settings (file-only; no CP screen) -------------------------
    // These behaviour knobs have no Control Panel form in this release — they
    // run at their DB-column defaults unless you set them here. Resolution order
    // is: value in this file > stored DB value > Settings model default.

    // Days to retain AI-bot hit logs before garbage collection. Default: 30.
    // 'botLogRetentionDays' => 30,

    // Record unhandled 404s for the suggested-redirects screen. Default: true.
    // 'log404s' => true,

    // Days to retain rows in the 404 log before GC. Default: 90.
    // 'log404RetentionDays' => 90,

    // Days after which an entry's content is considered stale (dashboard/scoring). Default: 90.
    // 'staleThresholdDays' => 90,

    // Per-request meta cache duration in seconds. null = follow Craft's cacheDuration. Default: null.
    // 'metaCacheDuration' => null,

    // --- SEO field UI ---------------------------------------------------------
    // Lite mode trims the entry SEO field: char meters + Google preview only,
    // no checklist, inheritance badges, or soft length hints. Default: true.
    // Set false here only if you want the full SEO field UI.
    // 'seoFieldLiteMode' => false,

    // --- Social images --------------------------------------------------------
    // Craft image transform handle applied to Open Graph / Twitter images.
    // Use 'none', 'original', or 'full' to serve the asset URL untransformed.
    // Developer-level setting (transforms are defined in code/CP), so it lives
    // here rather than in the Control Panel. Default: 'beaconSocial'.
    // 'socialImageTransform' => 'beaconSocial',

    // --- hreflang (file-only; no CP screen) -----------------------------------
    // Emit hreflang alternates with x-default. Opt-in. Default: false.
    // 'hreflangEnabled' => true,
    // Site handle used as x-default; null = no x-default. Default: null.
    // 'hreflangXDefaultSiteHandle' => null,

    // --- GEO Markdown ---------------------------------------------------------
    // Entry field handle used as the Markdown body source. Default: 'body'.
    // 'geoMarkdownBodyFieldHandle' => 'body',

    // Section handles whose entries are eligible for Markdown export.
    // Empty = all public sections. Default: [].
    // 'geoMarkdownSectionAllowlist' => ['blog', 'docs'],

    // Cap the exported Markdown body at this many characters (cut on a word
    // boundary, with an ellipsis). Omit / null = export the full content.
    // 'geoMarkdownExcerptLength' => 500,

    // Site-level default front-matter keys merged into every Markdown export
    // (lowest precedence — per-section and per-entry front matter override).
    // Default: [].
    // 'geoMarkdownFrontMatterDefaults' => ['license' => 'CC-BY-4.0'],

    // Render the entry's full Twig template then convert to Markdown (true), or
    // pull from the body field (false). Developer setting. Default: true.
    // 'geoMarkdownFullPageRender' => true,

    // CSS class names stripped from rendered HTML before Markdown conversion
    // (nav, footer, ad containers, etc.). Theme-specific. Default: [].
    // 'geoMarkdownExcludedClasses' => ['site-nav', 'site-footer', 'ad'],

    // --- GEO score (developer tuning) -----------------------------------------
    // Section handles whose entries get a GEO score. Empty = all. Default: [].
    // 'geoScoreSectionAllowlist' => ['blog', 'news'],

    // How the structural pillars read entry content:
    //   ''           follow geoMarkdownFullPageRender (default)
    //   'bodyField'  body field only (fast; misses Matrix / Twig composition)
    //   'fullRender' full template render (accurate, slower)
    // 'geoScoreContentRenderMode' => '',

    // Fact-density target — "1 fact per N words" (default 80). Bounds 30–400.
    // 'geoScoreFactDensityTarget' => 80,

    // Authority-domain overrides for the outbound-citation pillar. Each row is
    // ['domain' => '…', 'tier' => 1|2] to add a domain, or
    // ['domain' => '…', 'enabled' => false] to drop a bundled default.
    // 'geoScoreAuthorityDomainOverrides' => [
    //     ['domain' => 'acme-research.org', 'tier' => 1],
    //     ['domain' => 'wikipedia.org', 'enabled' => false],
    // ],

    // --- Breadcrumbs ----------------------------------------------------------
    // Auto-emitted BreadcrumbList JSON-LD is derived from this file plus the
    // site's home entry (there is no DB row for breadcrumbs).
    // Emit BreadcrumbList JSON-LD. Default: true.
    // 'breadcrumbsEnabled' => true,
    // First-crumb ("home") label per site handle; falls back to the home entry title.
    // 'breadcrumbsHomeLabel' => [
    //     'default' => 'Home',
    //     'fr'      => 'Accueil',
    // ],

    // --- IndexNow -------------------------------------------------------------
    // Fan out live entry saves to the IndexNow consortium. Default: false.
    // 'indexNowEnabled' => false,
    // Per-site IndexNow keys, keyed by site handle. File-only (overrides the
    // per-site key under Settings → Webmaster). Default: not set.
    // 'indexNowKeys' => [
    //     'default' => 'your-32-char-indexnow-key',
    //     'fr'      => 'another-key-for-the-fr-site',
    // ],

    // --- Schema.org type catalogue (file-only) --------------------------------
    // Add extra schema.org types to the SEO field's "Add schema" modal beyond
    // the built-in Article / Product / Recipe / HowTo / FAQPage / Review set.
    // 'schemaTypes' => ['Event', 'JobPosting', 'SoftwareApplication'],

    // Ship the full ~900-type schema.org catalogue in the dropdown. Off by
    // default because the list gets long. Default: false.
    // 'fullSchemaCatalogue' => false,
];
