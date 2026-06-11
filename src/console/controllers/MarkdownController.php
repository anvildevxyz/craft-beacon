<?php

namespace anvildev\beacon\console\controllers;

use anvildev\beacon\console\SiteHandleResolverTrait;
use anvildev\beacon\helpers\Strings;
use anvildev\beacon\integrations\CommerceIntegration;
use anvildev\beacon\jobs\GenerateGeoMarkdownJob;
use anvildev\beacon\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\console\Controller;
use craft\elements\Entry;
use yii\console\ExitCode;

/**
 * Pre-generation and cache-clear commands for GEO Markdown:
 *
 *     php craft beacon/markdown/generate
 *     php craft beacon/markdown/clear
 *
 * Use `--site=<handle>` to scope to a single site, `--section=<handle>` to
 * scope to a single section (Entries only), `--type=entries|products|all` to
 * scope by element type, and `--sync` to switch from the default async
 * (Craft queue jobs) to inline generation.
 */
class MarkdownController extends Controller
{
    use SiteHandleResolverTrait;

    public ?string $site = null;
    public ?string $section = null;
    public string $type = 'all';
    public bool $sync = false;
    public ?int $limit = null;

    /**
     * @return list<string>
     */
    public function options($actionID): array
    {
        return match ($actionID) {
            'generate' => ['site', 'section', 'type', 'sync', 'limit'],
            'clear' => ['site', 'section', 'type'],
            default => [],
        };
    }

    /**
     * Pre-generate GEO Markdown for live entries (and Commerce products). By
     * default work is queued (`--sync` generates inline). Scope with
     * `--site`, `--section`, `--type=entries|products|all`, and `--limit`.
     * No-ops with exit code CONFIG when GEO Markdown is disabled.
     */
    public function actionGenerate(): int
    {
        $plugin = Plugin::$plugin;
        $settings = $plugin->settings->get();
        if (!$settings->geoMarkdownEnabled) {
            $this->stderr("GEO Markdown is disabled (Settings → Behavior → GEO Markdown).\n");
            return ExitCode::CONFIG;
        }

        $sites = $this->resolveSitesOrAll();
        if ($sites === []) {
            return ExitCode::CONFIG;
        }

        $allowlist = $settings->geoMarkdownSectionAllowlist;
        $sectionFilter = Strings::trimToNull($this->section);
        $useQueue = !$this->sync;
        $limit = ($this->limit !== null && $this->limit > 0) ? $this->limit : null;
        $wantEntries = $this->type === 'all' || $this->type === 'entries';
        $wantProducts = ($this->type === 'all' || $this->type === 'products') && CommerceIntegration::isMarkdownEligible();
        $queueSvc = Craft::$app->getQueue();
        $totalQueued = 0;
        $totalGenerated = 0;
        $totalSkipped = 0;

        foreach ($sites as $site) {
            $siteId = (int) $site->id;
            $elementIds = [];

            if ($wantEntries) {
                $entryQuery = Entry::find()->siteId($siteId)->status(Entry::STATUS_LIVE);
                if ($sectionFilter !== null) {
                    $entryQuery->section($sectionFilter);
                } elseif ($allowlist !== []) {
                    $entryQuery->section($allowlist);
                }
                if ($limit !== null) {
                    $entryQuery->limit($limit);
                }
                $elementIds = $entryQuery->ids();
            }

            if ($wantProducts) {
                /** @phpstan-ignore-next-line — Commerce is an optional dependency */
                $productClass = \craft\commerce\elements\Product::class;
                $productQuery = $productClass::find()->siteId($siteId)->status($productClass::STATUS_LIVE);
                if ($limit !== null) {
                    $productQuery->limit($limit);
                }
                $elementIds = [...$elementIds, ...$productQuery->ids()];
            }

            if ($elementIds === []) {
                continue;
            }

            $this->stdout(sprintf("[%s] %d elements\n", $site->handle, count($elementIds)));

            if ($useQueue) {
                foreach ($elementIds as $elementId) {
                    $queueSvc->push(new GenerateGeoMarkdownJob([
                        'siteId' => $siteId,
                        'elementId' => (int) $elementId,
                    ]));
                    $totalQueued++;
                }
                continue;
            }

            foreach ($elementIds as $elementId) {
                $element = CommerceIntegration::findLiveMarkdownElement('id', (int) $elementId, $siteId);
                if (!$element instanceof ElementInterface) {
                    $totalSkipped++;
                    continue;
                }
                $markdown = $plugin->geoMarkdownExport->exportElement($element);
                if ($markdown === null) {
                    $totalSkipped++;
                    continue;
                }
                $plugin->geoMarkdownStore->put($siteId, (int) $elementId, $markdown);
                $totalGenerated++;
            }
        }

        $this->stdout($useQueue
            ? "Queued $totalQueued markdown generation job(s).\n"
            : "Generated $totalGenerated, skipped $totalSkipped.\n");
        return ExitCode::OK;
    }

    /**
     * Clear stored GEO Markdown. With no `--section`/`--type` filter the whole
     * store is cleared per site; otherwise only the matching elements are
     * removed. Scope with `--site`, `--section`, and `--type=entries|products|all`.
     */
    public function actionClear(): int
    {
        $sites = $this->resolveSitesOrAll();
        if ($sites === []) {
            return ExitCode::CONFIG;
        }

        $store = Plugin::$plugin->geoMarkdownStore;
        $sectionFilter = Strings::trimToNull($this->section);
        $wantEntries = $this->type === 'all' || $this->type === 'entries';
        $wantProducts = ($this->type === 'all' || $this->type === 'products') && CommerceIntegration::isInstalled();
        $clearAll = $sectionFilter === null && ($this->type === 'all' || $this->type === '');
        $deleted = 0;

        foreach ($sites as $site) {
            $siteId = (int) $site->id;
            if ($clearAll) {
                $deleted += $store->clear($siteId);
                continue;
            }

            $ids = [];
            if ($wantEntries && $sectionFilter !== null) {
                $ids = Entry::find()
                    ->siteId($siteId)
                    ->section($sectionFilter)
                    ->status(null)
                    ->ids();
            }
            if ($wantProducts) {
                /** @phpstan-ignore-next-line — Commerce is an optional dependency */
                $ids = [...$ids, ...\craft\commerce\elements\Product::find()
                    ->siteId($siteId)
                    ->status(null)
                    ->ids(), ];
            }
            foreach ($ids as $elementId) {
                $deleted += $store->clear($siteId, (int) $elementId);
            }
        }

        $this->stdout("Cleared $deleted pre-generated markdown row(s).\n");
        return ExitCode::OK;
    }
}
