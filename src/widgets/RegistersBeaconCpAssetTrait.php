<?php

namespace anvildev\beacon\widgets;

use anvildev\beacon\web\assets\cp\BeaconCpAsset;
use Craft;

/**
 * Dashboard widgets render outside {@see \anvildev\beacon\Plugin}'s
 * `beacon/*` template hook, so they must register CP assets explicitly.
 */
trait RegistersBeaconCpAssetTrait
{
    private function registerBeaconCpAsset(): void
    {
        Craft::$app->getView()->registerAssetBundle(BeaconCpAsset::class);
    }
}
