<?php

namespace anvildev\beacon\widgets;

use anvildev\beacon\Plugin;
use Craft;
use craft\base\Widget;

/**
 * Dashboard widget surfacing recent IndexNow submissions.
 *
 * Why this exists: under Craft's default prod log target, `Craft::info()` is
 * suppressed — so the "IndexNow: submitted N url(s)" line our service logs
 * is invisible to operators. The widget reads from
 * `beacon_indexnow_submissions` (populated by {@see \anvildev\beacon\services\IndexNowService::submit()})
 * so the activity is always inspectable in the CP regardless of log level.
 */
final class IndexNowActivityWidget extends Widget
{
    use WidgetRangeTrait;
    use DefaultsToTwoColumnsTrait;
    use RegistersBeaconCpAssetTrait;

    public string $range = '7d';

    public static function displayName(): string
    {
        return Craft::t('beacon', 'widgets.indexNow.indexnow.activity');
    }

    public static function icon(): ?string
    {
        return 'paper-plane';
    }

    public function getTitle(): ?string
    {
        return Craft::t('beacon', 'widgets.indexNow.indexnow.activity');
    }

    public function getBodyHtml(): ?string
    {
        $this->registerBeaconCpAsset();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $indexNow = Plugin::$plugin->indexNow;

        return Craft::$app->getView()->renderTemplate('beacon/_widgets/indexnow-activity', [
            'counts' => $indexNow->recentCounts(self::rangeToHours($this->range), $siteId),
            'rows' => $indexNow->recentSubmissions(15, $siteId),
            'range' => $this->range,
            'sites' => Craft::$app->getSites()->getAllSites(),
        ]);
    }

    protected function rangeSettingsTemplate(): string
    {
        return 'beacon/_widgets/indexnow-activity-settings';
    }
}
