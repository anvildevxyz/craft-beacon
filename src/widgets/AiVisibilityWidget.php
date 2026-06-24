<?php

namespace anvildev\beacon\widgets;

use anvildev\beacon\Plugin;
use Craft;
use craft\base\Widget;

/**
 * Dashboard widget: answer-engine citation rate for the current site plus a
 * short list of the latest probes. Reads from {@see \anvildev\beacon\services\AiVisibilityService}.
 */
final class AiVisibilityWidget extends Widget
{
    use WidgetRangeTrait;
    use DefaultsToTwoColumnsTrait;
    use RegistersBeaconCpAssetTrait;

    public string $range = '30d';

    public static function displayName(): string
    {
        return Craft::t('beacon', 'widgets.aiVisibility.title');
    }

    public static function icon(): ?string
    {
        return 'magnifying-glass';
    }

    public function getTitle(): ?string
    {
        return Craft::t('beacon', 'widgets.aiVisibility.title');
    }

    public function getBodyHtml(): ?string
    {
        $this->registerBeaconCpAsset();
        $service = Plugin::$plugin->aiVisibility;
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $withinDays = (int) (self::rangeToHours($this->range) / 24) ?: 1;

        $data = Craft::$app->getCache()->getOrSet(
            "beacon.aiVisibilityWidget:$siteId:{$this->range}",
            fn(): array => [
                'citationRate' => $service->citationRate($siteId, $withinDays),
                'results' => array_slice($service->latestResults($siteId, 8), 0, 8),
            ],
            60,
        );

        return Craft::$app->getView()->renderTemplate('beacon/_widgets/ai-visibility', [
            'citationRate' => $data['citationRate'],
            'results' => $data['results'],
            'range' => $this->range,
            'isActive' => $service->isActive(),
        ]);
    }

    protected function rangeSettingsTemplate(): string
    {
        return 'beacon/_widgets/bot-activity-settings';
    }
}
