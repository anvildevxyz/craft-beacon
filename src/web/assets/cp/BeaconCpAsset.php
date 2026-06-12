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
     * Semantic keys used by beacon-cp.js via Craft.t('beacon', …).
     *
     * @var list<string>
     */
    private const TRANSLATIONS = [
        'cp.js.could.not.save.change',
        'cp.js.disabled',
        'cp.js.enabled',
        'cp.js.shown.of.total.shown',
    ];

    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        if ($view instanceof View) {
            $view->registerTranslations('beacon', self::TRANSLATIONS);
        }
    }
}
