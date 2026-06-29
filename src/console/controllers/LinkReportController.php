<?php

namespace anvildev\beacon\console\controllers;

use anvildev\beacon\console\SiteHandleResolverTrait;
use anvildev\beacon\Plugin;
use craft\console\Controller;
use yii\console\ExitCode;

/**
 * Prints link reports (orphan pages, overview statistics) to the console.
 *
 * Command id: `link-report`.
 *
 * @author Anvil
 * @since 1.0.0
 */
class LinkReportController extends Controller
{
    // =========================================================================
    // Traits
    // =========================================================================

    use SiteHandleResolverTrait;
    use RequiresLinksEnabledConsoleTrait;

    // =========================================================================
    // Public Properties
    // =========================================================================

    public ?string $site = null;
    public ?string $section = null;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @param string $actionID
     * @return list<string>
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'site';
        if ($actionID === 'orphans') {
            $options[] = 'section';
        }
        return $options;
    }

    /**
     * Lists orphan pages (entries with no inbound internal links).
     */
    public function actionOrphans(): int
    {
        if (($exit = $this->exitIfLinksDisabled()) !== null) {
            return $exit;
        }

        $siteId = $this->resolveSiteIdOrPrimary();
        if ($siteId === null) {
            return ExitCode::CONFIG;
        }

        $orphans = Plugin::$plugin->links->reports->getOrphanPages($siteId, $this->section);
        if ($orphans === []) {
            $this->stdout("No orphan pages found.\n");
            return ExitCode::OK;
        }

        $this->stdout(sprintf("Found %d orphan pages:\n\n", count($orphans)));
        $this->stdout(str_pad('ID', 8) . str_pad('Section', 20) . str_pad('Outbound', 10) . "Title\n");
        $this->stdout(str_repeat('-', 80) . "\n");
        foreach ($orphans as $orphan) {
            $this->stdout(sprintf(
                "%s%s%s%s\n",
                str_pad((string) $orphan['elementId'], 8),
                str_pad($orphan['sectionName'], 20),
                str_pad((string) $orphan['outboundCount'], 10),
                $orphan['title'],
            ));
        }
        return ExitCode::OK;
    }

    /**
     * Prints overview statistics for the resolved site.
     */
    public function actionStats(): int
    {
        if (($exit = $this->exitIfLinksDisabled()) !== null) {
            return $exit;
        }

        $siteId = $this->resolveSiteIdOrPrimary();
        if ($siteId === null) {
            return ExitCode::CONFIG;
        }

        Plugin::$plugin->links->reports->invalidateCache();
        $stats = Plugin::$plugin->links->reports->getOverviewStats($siteId, 60);
        $this->stdout("Beacon Link Statistics\n");
        $this->stdout(str_repeat('=', 40) . "\n");
        $this->stdout(sprintf("Entries indexed:     %d / %d\n", $stats['totalIndexed'], $stats['totalEntries']));
        $this->stdout(sprintf("Avg links per entry: %.1f\n", $stats['averageLinks']));
        $this->stdout(sprintf("Orphan pages:        %d\n", $stats['orphanCount']));
        $this->stdout(sprintf("No outbound links:   %d\n", $stats['noOutboundCount']));
        $this->stdout(sprintf("Acceptance rate:     %.0f%%\n", $stats['acceptanceRate'] * 100));
        return ExitCode::OK;
    }
}
