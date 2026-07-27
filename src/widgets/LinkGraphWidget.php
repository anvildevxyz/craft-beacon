<?php

namespace anvildev\beacon\widgets;

use anvildev\beacon\Plugin;
use Craft;
use craft\base\Widget;

/**
 * Dashboard widget summarizing internal-link health: indexed entries, orphan
 * pages, broken links, and average links per entry, with a link to the Links
 * overview.
 *
 * Ported from Whisper's `WhisperWidget`.
 *
 * @author Anvil
 * @since 1.0.0
 */
final class LinkGraphWidget extends Widget
{
    // =========================================================================
    // Traits
    // =========================================================================

    use RegistersBeaconCpAssetTrait;

    // =========================================================================
    // Public Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('beacon', 'widgets.linkGraph.link.graph');
    }

    public static function icon(): ?string
    {
        return 'diagram-project';
    }

    public function getTitle(): ?string
    {
        return Craft::t('beacon', 'widgets.linkGraph.link.graph');
    }

    public function getBodyHtml(): ?string
    {
        if (!Plugin::$plugin->links->isEnabled()) {
            return '<p class="light">' . Craft::t('beacon', 'links.disabled.widget') . '</p>';
        }

        $this->registerBeaconCpAsset();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        return Craft::$app->getView()->renderTemplate('beacon/_widgets/link-graph', [
            'data' => Craft::$app->getCache()->getOrSet(
                "beacon.linkGraphWidget:$siteId",
                fn() => $this->loadData($siteId),
                60,
            ),
        ]);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * @return array{stats: array<string, mixed>, brokenCount: int}
     */
    private function loadData(int $siteId): array
    {
        $links = Plugin::$plugin->links;
        $settings = $links->getSettings();

        return [
            'stats' => $links->reports->getOverviewStats($siteId, $settings->reportCacheDuration),
            'brokenCount' => count($links->brokenLinks->findBroken($siteId)),
        ];
    }
}
