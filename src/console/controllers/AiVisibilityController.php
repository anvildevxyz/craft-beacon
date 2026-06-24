<?php

namespace anvildev\beacon\console\controllers;

use anvildev\beacon\Plugin;
use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * CLI surface for answer-engine visibility tracking:
 *   craft beacon/ai-visibility/run               run for every site
 *   craft beacon/ai-visibility/run --site=blog   run for one site
 *   craft beacon/ai-visibility/gc                purge results past the retention window
 *
 * Honors the `aiVisibilityEnabled` toggle and the AI provider config — a
 * dormant install runs nothing.
 */
class AiVisibilityController extends Controller
{
    public ?string $site = null;

    /**
     * @return list<string>
     */
    public function options($actionId): array
    {
        $options = parent::options($actionId);
        if ($actionId === 'run') {
            $options[] = 'site';
        }
        return $options;
    }

    /**
     * Run benchmark prompts against the configured answer engine(s) and record citations.
     */
    public function actionRun(): int
    {
        $service = Plugin::$plugin->aiVisibility;
        if (!$service->isActive()) {
            $this->stdout("AI-visibility tracking is not active (enable it and configure an AI provider).\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $siteIds = $this->resolveSiteIds();
        $totalCited = 0;
        $totalEvaluated = 0;
        foreach ($siteIds as $siteId) {
            $summary = $service->run($siteId);
            $totalCited += $summary['cited'];
            $totalEvaluated += $summary['evaluated'];
            $this->stdout(sprintf(
                "Site %d: %d evaluated, %d cited, %d failed.\n",
                $siteId,
                $summary['evaluated'],
                $summary['cited'],
                $summary['failed'],
            ));
        }

        $this->stdout(sprintf("Done: %d probes, %d citations.\n", $totalEvaluated, $totalCited), Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Delete visibility results older than the configured retention window.
     */
    public function actionGc(): int
    {
        $days = Plugin::$plugin->settings->get()->aiVisibilityResultRetentionDays;
        $deleted = Plugin::$plugin->aiVisibility->gc($days);
        $this->stdout("Deleted {$deleted} visibility result row(s) older than {$days} days.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * @return list<int>
     */
    private function resolveSiteIds(): array
    {
        if ($this->site !== null) {
            $site = Craft::$app->getSites()->getSiteByHandle($this->site);
            return $site !== null ? [$site->id] : [];
        }
        return array_map(static fn($s): int => $s->id, Craft::$app->getSites()->getAllSites());
    }
}
