<?php

namespace anvildev\beacon\web\assets\entities;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View;

/**
 * Powers the Beacon SEO field's entity (Wikidata) picker. Registered by
 * {@see \anvildev\beacon\fields\BeaconSeoField}. Hand-authored (no build
 * step); reads its config from the field container and calls the
 * `beacon/entities/search` action.
 */
final class BeaconEntitiesAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';
    public $depends = [CpAsset::class];
    public $js = ['beacon-entities.js?v=1'];

    /**
     * Source strings used via Craft.t('beacon', …) in beacon-entities.js.
     */
    private const TRANSLATIONS = [
        'entities.search.placeholder',
        'entities.searching',
        'entities.noResults',
        'entities.about',
        'entities.mentions',
        'entities.remove',
        'entities.addManual',
        'entities.manualUrlPrompt',
    ];

    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        if ($view instanceof View) {
            $view->registerTranslations('beacon', self::TRANSLATIONS);
        }
    }
}
