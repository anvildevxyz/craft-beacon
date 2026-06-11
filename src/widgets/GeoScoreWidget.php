<?php

namespace anvildev\beacon\widgets;

use anvildev\beacon\Plugin;
use Craft;
use craft\base\Widget;

/**
 * Dashboard widget for the GEO content score: site average, distribution
 * histogram (5 bands), and the 5 weakest entries with a "what to fix
 * first" pillar callout each.
 *
 * @phpstan-import-type GeoScoreDistribution from \anvildev\beacon\services\GeoScoreService
 * @phpstan-import-type GeoWeakestPillarRow from \anvildev\beacon\services\GeoScoreService
 *
 * @phpstan-type GeoScoreWidgetData array{
 *   average: ?int,
 *   distribution: GeoScoreDistribution,
 *   weakest: list<GeoWeakestPillarRow>,
 * }
 *
 * The widget reads from `{{%beacon_geo_score}}` only — no live recompute.
 * Editors trigger fresh scores by saving entries (the
 * {@see \anvildev\beacon\jobs\RecomputeGeoScoreJob} runs async on
 * `Element::EVENT_AFTER_SAVE`); operators with the `beacon:editGeoScore`
 * permission can also manual-recompute from the per-entry drill-down.
 *
 * Cached for 60 seconds per (site, range) — the data is essentially
 * static during a single dashboard view.
 */
final class GeoScoreWidget extends Widget
{
    use DefaultsToTwoColumnsTrait;
    use RegistersBeaconCpAssetTrait;

    public static function displayName(): string
    {
        return Craft::t('beacon', 'GEO content score');
    }

    public static function icon(): ?string
    {
        return 'chart-line';
    }

    public function getTitle(): ?string
    {
        return Craft::t('beacon', 'GEO content score');
    }

    public function getBodyHtml(): ?string
    {
        $this->registerBeaconCpAsset();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $data = Craft::$app->getCache()->getOrSet(
            "beacon.geoScoreWidget:$siteId",
            fn() => $this->loadData($siteId),
            60,
        );

        return Craft::$app->getView()->renderTemplate('beacon/_widgets/geo-score', [
            'siteId' => $siteId,
            'average' => $data['average'],
            'distribution' => $data['distribution'],
            'weakest' => $data['weakest'],
            'hasScores' => $data['distribution']['total'] > 0,
        ]);
    }

    /** @return GeoScoreWidgetData */
    private function loadData(int $siteId): array
    {
        $svc = Plugin::$plugin->geoScore;
        return [
            'average' => $svc->siteAverage($siteId),
            'distribution' => $svc->distribution($siteId),
            'weakest' => $svc->weakestPillars($siteId, 5),
        ];
    }
}
