<?php

namespace anvildev\beacon\widgets;

use anvildev\beacon\elements\RedirectElement;
use anvildev\beacon\helpers\Db;
use anvildev\beacon\Plugin;
use Craft;
use craft\base\Widget;

/**
 * @phpstan-import-type RedirectActivityRow from \anvildev\beacon\types\ArrayShapes
 *
 * @phpstan-type RedirectActivityWidgetData array{
 *     recent: list<RedirectActivityRow>,
 *     topHits: list<RedirectActivityRow>,
 *     staleCount: int,
 *     staleThresholdDays: int,
 *     totalRedirects: int,
 *     unhandled404s24h: int,
 * }
 */
final class RedirectActivityWidget extends Widget
{
    use WidgetRangeTrait;
    use DefaultsToTwoColumnsTrait;
    use RegistersBeaconCpAssetTrait;

    public string $range = '7d';

    public static function displayName(): string
    {
        return Craft::t('beacon', 'widgets.redirectActivity.redirect.activity');
    }

    public static function icon(): ?string
    {
        return 'arrow-right-arrow-left';
    }

    public function getTitle(): ?string
    {
        return Craft::t('beacon', 'widgets.redirectActivity.redirect.activity');
    }

    public function getBodyHtml(): ?string
    {
        $this->registerBeaconCpAsset();
        $since = Db::cutoff(self::rangeToHours($this->range), 'hours');
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        return Craft::$app->getView()->renderTemplate('beacon/_widgets/redirect-activity', [
            'data' => Craft::$app->getCache()->getOrSet(
                "beacon.redirectWidget:$siteId:{$this->range}",
                fn() => $this->loadData($siteId, $since),
                60,
            ),
            'range' => $this->range,
        ]);
    }

    protected function rangeSettingsTemplate(): string
    {
        return 'beacon/_widgets/redirect-activity-settings';
    }

    /** @return RedirectActivityWidgetData */
    private function loadData(int $siteId, string $since): array
    {
        $plugin = Plugin::$plugin;
        $thresholdDays = $plugin->settings->get()->staleThresholdDays;

        $recentQuery = RedirectElement::find()
            ->siteId($siteId)
            ->status(null);

        // Craft 6's legacy andWhere() shim (CraftCms\Yii2Adapter\Mixins\
        // ElementQueryMixin) mis-renders any condition that compiles with
        // zero bound params — a pure null check like this one — into
        // garbled SQL. whereNotNull() is the real Laravel builder method
        // and bypasses that shim entirely. It's reached via ElementQuery's
        // __call()-forwarding to the underlying Illuminate builder, not a
        // declared method, so method_exists() on the query returns false
        // even on Craft 6 — check for the native class instead. Doesn't
        // exist on Craft 5's Yii-based ElementQuery, so this falls back to
        // the original (unaffected there) call. See
        // RedirectService::recentlyHit() for the live-reproduced error.
        if (class_exists(\CraftCms\Cms\Element\Queries\ElementQuery::class)) {
            $recentQuery->whereNotNull('beacon_redirects.lastHit');
        } else {
            $recentQuery->andWhere(['not', ['beacon_redirects.lastHit' => null]]);
        }

        /** @var list<RedirectElement> $recentRecords */
        $recentRecords = $recentQuery
            ->andWhere(['>=', 'beacon_redirects.lastHit', $since])
            ->orderBy(['beacon_redirects.lastHit' => SORT_DESC])
            ->limit(10)
            ->all();

        /** @var list<RedirectElement> $topRecords */
        $topRecords = RedirectElement::find()
            ->siteId($siteId)
            ->status(null)
            ->orderBy(['beacon_redirects.hits' => SORT_DESC])
            ->limit(10)
            ->all();

        return [
            'recent' => array_map([$this, 'shape'], $recentRecords),
            'topHits' => array_map([$this, 'shape'], $topRecords),
            'staleCount' => count($plugin->redirects->audit($siteId, $thresholdDays)),
            'staleThresholdDays' => $thresholdDays,
            'totalRedirects' => (int) RedirectElement::find()->siteId($siteId)->status(null)->count(),
            'unhandled404s24h' => $plugin->redirect404Log->countUnhandledSince($siteId, Db::cutoff(24, 'hours')),
        ];
    }

    /**
     * @return RedirectActivityRow
     */
    private function shape(RedirectElement $r): array
    {
        return [
            'id' => (int) $r->id,
            'sourceUri' => (string) $r->sourceUri,
            'targetUri' => (string) $r->targetUri,
            'statusCode' => (int) $r->statusCode,
            'lastHit' => $r->lastHit !== null ? (string) $r->lastHit : null,
            'hits' => (int) $r->hits,
        ];
    }
}
