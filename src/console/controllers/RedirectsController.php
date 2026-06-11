<?php

namespace anvildev\beacon\console\controllers;

use anvildev\beacon\console\SiteHandleResolverTrait;
use anvildev\beacon\Plugin;
use Craft;
use craft\console\Controller;
use yii\console\ExitCode;

class RedirectsController extends Controller
{
    use SiteHandleResolverTrait;

    public ?string $site = null;
    public int $thresholdDays = 90;

    /**
     * @return list<string>
     */
    public function options($actionID): array
    {
        return ['site', 'thresholdDays'];
    }

    /**
     * Import redirects from a CSV file. Rows are inserted in a single
     * transaction for the resolved site (`--site=<handle>`, default = primary);
     * prints inserted/skipped counts and per-line errors.
     */
    public function actionImport(string $csvFile): int
    {
        if (!is_readable($csvFile)) {
            $this->stderr("File not readable: $csvFile\n");
            return ExitCode::DATAERR;
        }

        $siteId = $this->resolveSiteIdOrPrimary();
        if ($siteId === null) {
            return ExitCode::CONFIG;
        }
        $result = Plugin::$plugin->redirectImporter->importFromCsv(
            (string) file_get_contents($csvFile),
            $siteId,
        );

        $this->stdout("Imported: {$result->insertedCount}\n");
        $this->stdout("Skipped:  {$result->skippedCount}\n");
        if ($result->errors !== []) {
            $this->stdout("Errors:\n");
            foreach ($result->errors as $err) {
                $this->stdout("  Line {$err['lineNumber']}: {$err['reason']}\n");
            }
        }
        return ExitCode::OK;
    }

    /**
     * Audit redirects: lists stale rules (no hits in `--thresholdDays`, default
     * 90) plus chains (A→B→C) and loops (A→A / A→B→A). Scope with
     * `--site=<handle>`; omit it to audit all sites.
     */
    public function actionAudit(): int
    {
        if ($this->unknownSiteHandle()) {
            return ExitCode::CONFIG;
        }
        $siteId = $this->resolveSiteId();
        $stale = Plugin::$plugin->redirects->audit($siteId, $this->thresholdDays);

        if ($stale === []) {
            $this->stdout("No stale redirects.\n");
        } else {
            $this->stdout(sprintf("Stale redirects (no hits in %d+ days):\n", $this->thresholdDays));
            $this->stdout(sprintf("%-50s %-10s %-20s %s\n", 'Source', 'Hits', 'Last hit', 'Created'));
            foreach ($stale as $r) {
                $this->stdout(sprintf(
                    "%-50s %-10d %-20s %s\n",
                    substr($r['sourceUri'], 0, 50),
                    $r['hits'],
                    $r['lastHit'] ?? '—',
                    $r['dateCreated'],
                ));
            }
        }

        // Chains and loops require a concrete site graph; audit each resolved site.
        $siteIds = $siteId !== null
            ? [$siteId]
            : array_map(static fn($s): int => (int) $s->id, Craft::$app->getSites()->getAllSites());

        $issues = array_merge(...array_map(
            fn(int $sid): array => Plugin::$plugin->redirects->findChainsAndLoops($sid),
            $siteIds,
        ));

        if ($issues === []) {
            $this->stdout("No redirect chains or loops.\n");
            return ExitCode::OK;
        }

        $this->stdout("\nChains & loops:\n");
        foreach ($issues as $issue) {
            $this->stdout(sprintf("%-6s %s\n", strtoupper($issue['kind']), implode(' → ', $issue['hops'])));
        }
        return ExitCode::OK;
    }

    /**
     * Prune entries from the 404 log older than `--thresholdDays`. Handled
     * entries are kept for 7 days as housekeeping fallback regardless of
     * the threshold (the service hard-codes that).
     */
    public function actionPrune404Log(): int
    {
        $deleted = Plugin::$plugin->redirect404Log->prune($this->thresholdDays);
        $this->stdout("Pruned {$deleted} entries from the 404 log.\n");
        return ExitCode::OK;
    }
}
