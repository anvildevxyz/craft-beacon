<?php

namespace anvildev\beacon\widgets;

use anvildev\beacon\Plugin;
use Craft;
use craft\base\Widget;
use craft\db\Query;
use craft\elements\Entry;

/**
 * @phpstan-type MarkdownCoverageSectionRow array{handle: string, total: int, generated: int}
 * @phpstan-type MarkdownCoverageWidgetData array{
 *     enabled: bool,
 *     totalEligible: int,
 *     totalGenerated: int,
 *     coveragePercent: int,
 *     lastGenerated: ?string,
 *     queueDepth: int,
 *     bySection: list<MarkdownCoverageSectionRow>,
 * }
 */
final class MarkdownCoverageWidget extends Widget
{
    use DefaultsToTwoColumnsTrait;
    use RegistersBeaconCpAssetTrait;

    public static function displayName(): string
    {
        return Craft::t('beacon', 'GEO Markdown coverage');
    }

    public static function icon(): ?string
    {
        return 'list-check';
    }

    public function getTitle(): ?string
    {
        return Craft::t('beacon', 'GEO Markdown coverage');
    }

    public function getBodyHtml(): ?string
    {
        $this->registerBeaconCpAsset();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        return Craft::$app->getView()->renderTemplate('beacon/_widgets/markdown-coverage', [
            'data' => Craft::$app->getCache()->getOrSet(
                "beacon.markdownCoverage:$siteId",
                fn() => $this->loadData($siteId),
                60,
            ),
        ]);
    }

    /** @return MarkdownCoverageWidgetData */
    private function loadData(int $siteId): array
    {
        $plugin = Plugin::$plugin;
        $settings = $plugin->settings->get();

        if (!$settings->geoMarkdownEnabled) {
            return [
                'enabled' => false,
                'totalEligible' => 0,
                'totalGenerated' => 0,
                'coveragePercent' => 0,
                'lastGenerated' => null,
                'queueDepth' => 0,
                'bySection' => [],
            ];
        }

        $allowlist = $settings->geoMarkdownSectionAllowlist;
        $sectionHandles = $allowlist !== []
            ? $allowlist
            : array_map(static fn($s) => (string) $s->handle, Craft::$app->getEntries()->getAllSections());

        $generatedFlip = array_flip($plugin->geoMarkdownStore->existingElementIds($siteId));

        $bySection = [];
        $totalEligible = 0;
        $totalGenerated = 0;
        foreach ($sectionHandles as $handle) {
            $ids = Entry::find()
                ->siteId($siteId)
                ->section($handle)
                ->status(Entry::STATUS_LIVE)
                ->ids();
            $sectionGenerated = count(array_filter($ids, static fn($id) => isset($generatedFlip[(int) $id])));
            $sectionTotal = count($ids);
            $bySection[] = ['handle' => $handle, 'total' => $sectionTotal, 'generated' => $sectionGenerated];
            $totalEligible += $sectionTotal;
            $totalGenerated += $sectionGenerated;
        }

        $lastGenerated = (new Query())
            ->select(['MAX([[dateGenerated]])'])
            ->from(['{{%beacon_geo_markdown}}'])
            ->where(['siteId' => $siteId])
            ->scalar();

        return [
            'enabled' => true,
            'totalEligible' => $totalEligible,
            'totalGenerated' => $totalGenerated,
            'coveragePercent' => $totalEligible > 0 ? intdiv($totalGenerated * 100, $totalEligible) : 0,
            'lastGenerated' => is_string($lastGenerated) ? $lastGenerated : null,
            'queueDepth' => (int) (new Query())
                ->from(['{{%queue}}'])
                ->where(['like', 'description', 'Generating GEO Markdown'])
                ->count(),
            'bySection' => $bySection,
        ];
    }
}
