<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\Assets;
use Craft;

/**
 * Normalizes Craft element-select POST payloads (`[id]` or scalar) to asset IDs.
 */
trait AssetSelectorTrait
{
    private function assetIdFromSelector(mixed $raw): ?int
    {
        if (is_numeric($raw)) {
            $id = (int) $raw;
            return $id > 0 ? $id : null;
        }
        if (!is_array($raw) || $raw === []) {
            return null;
        }
        $id = (int) ($raw[0] ?? 0);
        return $id > 0 ? $id : null;
    }

    /**
     * Like {@see assetIdFromSelector()} but returns null unless the current user
     * can view the asset — prevents leaking private-volume URLs via CP settings.
     */
    private function viewableAssetIdFromSelector(mixed $raw): ?int
    {
        $id = $this->assetIdFromSelector($raw);
        if ($id === null) {
            return null;
        }
        $asset = Assets::findById($id, anyStatus: false);
        if ($asset === null) {
            return null;
        }
        $user = Craft::$app->getUser()->getIdentity();
        return ($user !== null && Craft::$app->getElements()->canView($asset, $user)) ? $id : null;
    }
}
