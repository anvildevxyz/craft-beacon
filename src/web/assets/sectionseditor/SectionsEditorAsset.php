<?php

namespace anvildev\beacon\web\assets\sectionseditor;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

final class SectionsEditorAsset extends AssetBundle
{
    public $sourcePath = '@anvildev/beacon/web/assets/sectionseditor/dist';
    public $depends = [CpAsset::class];
    public $js = ['sections-editor.js'];
}
