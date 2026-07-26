<?php

namespace anvildev\beacon\web\assets\links;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * CP asset bundle for the Links (internal-link-graph) feature: the entry
 * sidebar suggestion panel, CKEditor phrase insertion, and content highlights.
 *
 * Ported from Whisper's `web\assets\cp\CpAsset`.
 *
 * @author Anvil
 * @since 1.0.0
 */
final class LinksCpAsset extends AssetBundle
{
    public $sourcePath = '@anvildev/beacon/web/assets/links/dist';
    public $depends = [CpAsset::class];
    public $css = ['links.css'];
    public $js = [
        'links-highlights.js',
        'links-insert.js',
        'links-sidebar.js',
    ];
}
