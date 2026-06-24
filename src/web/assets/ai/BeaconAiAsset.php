<?php

namespace anvildev\beacon\web\assets\ai;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View;

/**
 * Adds the AI "Generate" affordances to the Beacon SEO field. Registered by
 * {@see \anvildev\beacon\fields\BeaconSeoField} only when AI generation is
 * enabled, so the script's presence means the feature is on. Hand-authored
 * (no build step); reads entry/site ids from the field container and calls the
 * `beacon/ai-content/*` actions.
 */
final class BeaconAiAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';
    public $depends = [CpAsset::class];
    public $js = ['beacon-ai.js?v=1'];

    /**
     * Source strings used via Craft.t('beacon', …) in beacon-ai.js.
     */
    private const TRANSLATIONS = [
        'ai.generate',
        'ai.generating',
    ];

    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        if ($view instanceof View) {
            $view->registerTranslations('beacon', self::TRANSLATIONS);
        }
    }
}
