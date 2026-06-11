<?php

namespace anvildev\beacon\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View;

final class BeaconCpAsset extends AssetBundle
{
    public $sourcePath = '@anvildev/beacon/web/assets/cp/dist';
    public $depends = [CpAsset::class];
    public $css = ['beacon-cp.css'];
    public $js = ['beacon-cp.js'];

    /**
     * Source strings used by beacon-cp.js via Craft.t('beacon', …), so they
     * are localized for the active CP language.
     */
    private const TRANSLATIONS = [
        'Add property',
        'Could not save change.',
        'Could not suggest mappings.',
        'Custom properties',
        'Disabled.',
        'Edit raw JSON',
        'Enabled.',
        'No curated properties for this type — add properties manually or use raw JSON.',
        'No sample entry of this type exists yet — values resolve as empty.',
        'Optional',
        'Preview',
        'Preview unavailable.',
        'Recommended',
        'Refresh',
        'Remove',
        'Order saved.',
        'Reorder failed. Refresh and try again.',
        'Delete the {label} schema?',
        'Schema deleted.',
        'Could not delete schema.',
        'this schema',
        '{shown} of {total} shown',
        '(QR render failed)',
        '/old/path',
        'Done.',
        'Error',
        'Queuing recompute…',
        'selected',
        'Rendered against sample entry:',
        'Required',
        'Suggest mappings',
        'Suggested mappings applied.',
        'This mapping produces no output for the sample entry yet.',
        'Use guided editor',
        'property',
    ];

    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        if ($view instanceof View) {
            $view->registerTranslations('beacon', self::TRANSLATIONS);
        }
    }
}
