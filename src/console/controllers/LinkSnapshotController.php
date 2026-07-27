<?php

namespace anvildev\beacon\console\controllers;

use anvildev\beacon\Plugin;
use anvildev\beacon\records\LinkRecord;
use Craft;
use craft\console\Controller;
use yii\console\ExitCode;

/**
 * Records a daily link-health snapshot per site, feeding the dashboard trend
 * sparklines.
 *
 * Command id: `link-snapshot`.
 *
 * @author Anvil
 * @since 1.0.0
 */
class LinkSnapshotController extends Controller
{
    use RequiresLinksEnabledConsoleTrait;

    /** @var string The only action; lets bare `beacon/link-snapshot` run it. */
    public $defaultAction = 'run';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Builds and stores a snapshot of link-health metrics for every site.
     */
    public function actionRun(): int
    {
        if (($exit = $this->exitIfLinksDisabled()) !== null) {
            return $exit;
        }

        $links = Plugin::$plugin->links;
        $sites = Craft::$app->getSites()->getAllSites();

        foreach ($sites as $site) {
            $siteId = $site->id;

            $stats = $links->reports->getOverviewStats($siteId, 0);
            $brokenLinks = $links->brokenLinks->findBroken($siteId);

            $totalInternalLinks = (int) LinkRecord::find()
                ->where(['sourceSiteId' => $siteId, 'isExternal' => false])
                ->count();

            $data = $links->trends->buildSnapshotData(
                orphanCount: $stats['orphanCount'],
                avgLinksPerPage: $stats['averageLinks'],
                totalInternalLinks: $totalInternalLinks,
                brokenLinkCount: count($brokenLinks),
                indexedEntryCount: $stats['totalIndexed'],
            );

            $links->trends->recordSnapshot($siteId, $data);

            $this->stdout("Snapshot recorded for site '{$site->name}':\n");
            $this->stdout(sprintf(
                "  Orphans: %d | Avg links: %.1f | Total internal: %d | Broken: %d | Indexed: %d\n",
                $data['orphanCount'],
                $data['avgLinksPerPage'],
                $data['totalInternalLinks'],
                $data['brokenLinkCount'],
                $data['indexedEntryCount'],
            ));
        }

        return ExitCode::OK;
    }
}
