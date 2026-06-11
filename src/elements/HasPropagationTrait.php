<?php

namespace anvildev\beacon\elements;

use Craft;
use craft\enums\PropagationMethod;
use craft\helpers\ArrayHelper;

/**
 * Multi-site propagation support for localized Beacon elements.
 *
 * Mirrors the pattern Booked uses for its Service element: a single
 * {@see PropagationMethod} drives {@see getSupportedSites()}, and Craft's
 * element save then creates/updates the matching `elements_sites` rows. All
 * element data is shared across the propagated sites (one data row per element
 * id) — propagation only controls *which* sites the element exists on.
 *
 * @property int|null $siteId
 */
trait HasPropagationTrait
{
    public PropagationMethod $propagationMethod = PropagationMethod::All;

    /**
     * @return list<int>
     */
    public function getSupportedSites(): array
    {
        $sites = Craft::$app->getSites();
        $currentSite = fn() => $sites->getSiteById($this->siteId) ?? $sites->getPrimarySite();
        return match ($this->propagationMethod) {
            PropagationMethod::All => ArrayHelper::getColumn($sites->getAllSites(), 'id'),
            PropagationMethod::SiteGroup => ArrayHelper::getColumn($sites->getSitesByGroupId($currentSite()->groupId), 'id'),
            PropagationMethod::Language => ArrayHelper::getColumn(
                array_filter($sites->getAllSites(), fn($s) => $s->language === $currentSite()->language),
                'id',
            ),
            default => [$this->siteId ?? $sites->getPrimarySite()->id],
        };
    }
}
