<?php

namespace anvildev\beacon\helpers;

use craft\elements\Asset;

/**
 * Cross-site asset lookups shared across controllers and services.
 *
 * Assets are non-localized; {@see Asset::find()} without {@see Asset::find()->siteId()}
 * scopes to the current site and can miss records on non-primary sites.
 */
final class Assets
{
    /**
     * Resolves an asset by id across all sites.
     *
     * @param bool $anyStatus When true, includes disabled assets via `status(null)`.
     */
    public static function findById(int $id, bool $anyStatus = true): ?Asset
    {
        if ($id <= 0) {
            return null;
        }

        $query = Asset::find()->id($id)->siteId('*');
        if ($anyStatus) {
            $query->status(null);
        }

        $asset = $query->one();
        return $asset instanceof Asset ? $asset : null;
    }
}
