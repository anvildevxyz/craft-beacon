<?php

namespace anvildev\beacon\web\assets\seofield;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View;

/**
 * Drives the Beacon SEO field's CP UI: char-count meters, schema-mapping
 * repeater, and the "+ Add schema" button. Loads once per CP request, scoped
 * via DOM `data-` attributes so no globals leak onto `window`.
 */
final class BeaconSeoFieldAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';
    public $depends = [CpAsset::class];
    public $js = ['seo-field.js?v=10', 'geo-score-chip.js?v=1'];
    public $css = ['seo-field.css'];

    /**
     * Source strings used by seo-field.js / geo-score-chip.js via
     * Craft.t('beacon', …), so they are localized for the active CP language.
     */
    private const TRANSLATIONS = [
        ' — aspect ratio off (1.91:1 recommended for OG/X)',
        ' — below 1200×630 recommendation',
        ' — looks good ✓',
        ' — too small (need ≥ 600 wide; 1200×630 recommended)',
        'Add at least one schema bundle or entry-level schema add-on for richer machine understanding.',
        'Add property',
        'Add schema',
        'All required + recommended properties mapped',
        'Below 120 characters — most SERPs have room for 150–160.',
        'Cancel',
        'Canonical must be an absolute URL beginning with http:// or https://',
        'Checking image…',
        'Close',
        'Could not load image to verify dimensions',
        'Could not load suggested mapping.',
        'Custom fields',
        'Desc {count} / {budget}',
        'Description is empty — Slack/Discord/Facebook cards will not show a snippet.',
        'Edit',
        'Edit schema',
        'Entry attributes',
        'Entry override',
        'Entry title fallback',
        'Expand SEO description toward 140–165 characters.',
        'Fill title + description so Open Graph/Twitter previews are complete.',
        'Global default',
        'Hide image',
        'Increase SEO title length toward 50–60 characters.',
        'JSON-LD preview',
        'Line {line}: expected "key: value" format.',
        'Loading…',
        'Looks good. Next: validate social image quality and keep title/description aligned with page intent.',
        'Missing recommended: {props}',
        'Missing required: {props}',
        'No social image — Open Graph / Twitter cards will render text-only.',
        "Open this type's spec on schema.org",
        'Optional',
        'Preview gaps',
        'Properties',
        'Property',
        'Re-check canonical after URL/slug changes.',
        'Recommended',
        'Remove',
        'Required',
        'Review robots directives: avoid combining noindex with nosnippet unless intentionally blocking AI/search visibility.',
        'SEO field',
        'Save',
        'Save the entry once before requesting a suggested mapping.',
        "Save this entry first — Beacon needs the entry's saved fields to suggest mappings.",
        'Schema type',
        'Section default',
        'Set canonical URL to an absolute http(s) URL or leave it blank for automatic canonical.',
        'Short titles can underperform — aim for 50–60 characters.',
        'Shorten SEO title to stay within 50–60 characters.',
        'Show with image',
        'Social image {w} × {h}px',
        'Source',
        'Suggest mapping',
        'Suggest mapping requires the Craft CP — open this entry in the CP.',
        'Tip: descriptions over 165 characters often truncate in SERP snippets.',
        'Tip: titles over 60 characters tend to truncate in Google mobile.',
        'Title is empty — Google and social cards will fall back to the entry title or be blank.',
        'Title {count} / {budget} chars',
        'Title {px}px / {budget}px',
        'Trim SEO description to avoid truncation in SERP snippets.',
        'noindex + noarchive is redundant: noindex already drops the page from results.',
        'noindex + nosnippet together also blocks AI/search visibility — confirm this is intentional.',
        'propertyName',
        'schema.org docs ↗',
        '{hit}/{total} recommended',
        '{hit}/{total} required',
        '— Custom property…',
        '— Custom template…',
        '— no properties mapped —',
    ];

    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        if ($view instanceof View) {
            $view->registerTranslations('beacon', self::TRANSLATIONS);
        }
    }
}
