<?php

namespace anvildev\beacon\console\controllers;

use anvildev\beacon\jobs\LinkBatchIndexJob;
use anvildev\beacon\Plugin;
use Craft;
use craft\console\Controller;
use craft\elements\Entry;
use yii\console\ExitCode;

/**
 * Queues link/keyword indexing jobs for entries and clears indexed link data.
 *
 * Command id: `link-index`.
 *
 * @author Anvil
 * @since 1.0.0
 */
class LinkIndexController extends Controller
{
    use RequiresLinksEnabledConsoleTrait;

    // =========================================================================
    // Const Properties
    // =========================================================================

    public const LAST_RUN_CACHE_KEY = 'beacon_links_last_index_run';

    // =========================================================================
    // Public Properties
    // =========================================================================

    /** Set to `last-run` to only index entries changed since the previous run. */
    public ?string $changedSince = null;

    /** Number of entries per queued batch job (clamped to 1–500). */
    public int $batchSize = 50;

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
        if ($actionID === 'index') {
            $options[] = 'changedSince';
            $options[] = 'batchSize';
        }
        return $options;
    }

    /**
     * Queues {@see LinkBatchIndexJob}s for every (enabled) entry on every site.
     */
    public function actionIndex(): int
    {
        if (($exit = $this->exitIfLinksDisabled()) !== null) {
            return $exit;
        }

        $settings = Plugin::$plugin->links->getSettings();
        $sites = Craft::$app->getSites()->getAllSites();
        $totalQueued = 0;
        $totalEntries = 0;

        $afterDate = null;
        if ($this->changedSince === 'last-run') {
            $lastRun = Craft::$app->getCache()->get(self::LAST_RUN_CACHE_KEY);
            if ($lastRun !== false && $lastRun !== null) {
                $afterDate = $lastRun;
                $this->stdout("Filtering entries updated after {$afterDate}.\n");
            } else {
                $this->stdout("No previous run timestamp found, indexing all entries.\n");
            }
        }

        foreach ($sites as $site) {
            $query = Entry::find()->siteId($site->id)->status(null);
            if ($settings->enabledSections !== []) {
                $query->sectionId(array_map(
                    static fn(string $uid) => Craft::$app->getEntries()->getSectionByUid($uid)?->id,
                    $settings->enabledSections,
                ));
            }
            if ($afterDate !== null) {
                $query->dateUpdated('>= ' . $afterDate);
            }
            $entries = $query->ids();
            $totalEntries += count($entries);
            $batchSize = max(1, min(500, $this->batchSize));
            foreach (array_chunk($entries, $batchSize) as $chunk) {
                Craft::$app->getQueue()->push(new LinkBatchIndexJob([
                    'entryIds' => array_map('intval', $chunk),
                    'siteId' => $site->id,
                ]));
                $totalQueued++;
            }
        }

        if ($totalEntries === 0) {
            $this->stdout("Warning: No entries matched the current filter criteria.\n");
        }

        if ($this->changedSince === 'last-run') {
            Craft::$app->getCache()->set(self::LAST_RUN_CACHE_KEY, date('Y-m-d H:i:s'));
        }

        $this->stdout("Queued {$totalQueued} jobs for {$totalEntries} entries.\n");
        return ExitCode::OK;
    }

    /**
     * Clears all indexed keywords, links, and suggestion interactions.
     */
    public function actionClear(): int
    {
        $links = Plugin::$plugin->links;
        $this->stdout("Clearing all Beacon link index data...\n");
        $links->index->clearAll();
        $links->linkScan->clearAll();
        $links->suggestions->clearAll();
        $this->stdout("Index cleared. Run 'php craft beacon/link-index' to rebuild.\n");
        return ExitCode::OK;
    }
}
