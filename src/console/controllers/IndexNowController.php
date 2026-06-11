<?php

namespace anvildev\beacon\console\controllers;

use anvildev\beacon\console\SiteHandleResolverTrait;
use anvildev\beacon\helpers\SeoFieldReader;
use anvildev\beacon\helpers\Strings;
use anvildev\beacon\models\WebmasterSettings;
use anvildev\beacon\Plugin;
use Craft;
use craft\console\Controller;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\helpers\Console;
use craft\models\Site;
use yii\console\ExitCode;

/**
 * CLI surface for IndexNow:
 *   craft beacon/index-now/submit <url>         single-URL ping
 *   craft beacon/index-now/section <handle>     submit every live entry in a section
 *   craft beacon/index-now/all                  submit every live entry across all sites
 *   craft beacon/index-now/generate-key         set/generate the per-site key
 *
 * Honors the global `indexNowEnabled` setting and the per-site key just like
 * the auto-submit listener.
 */
class IndexNowController extends Controller
{
    use SiteHandleResolverTrait;

    public ?string $site = null;

    /**
     * Explicit key for `generate-key` (`--key=<value>`); a random key is
     * generated when omitted.
     */
    public ?string $key = null;

    /**
     * @return list<string>
     */
    public function options($actionId): array
    {
        $options = parent::options($actionId);
        if (in_array($actionId, ['submit', 'section', 'all', 'generate-key'], true)) {
            $options[] = 'site';
        }
        if ($actionId === 'generate-key') {
            $options[] = 'key';
        }
        return $options;
    }

    /**
     * Generate (or set via `--key`) the per-site IndexNow key, persist it, and
     * print the key plus the ownership-proof URL search engines will fetch at
     * `/{key}.txt`.
     *
     * A key set in `config/beacon.php` under `indexNowKeys` takes precedence
     * over the stored value, so the saved key only takes effect for sites with
     * no config entry — the command warns when that's the case.
     */
    public function actionGenerateKey(): int
    {
        if (($site = $this->resolveSiteOrPrimary()) === null) {
            return ExitCode::CONFIG;
        }

        if ($this->key !== null) {
            $key = trim($this->key);
            if (preg_match('/^[a-zA-Z0-9-]{8,128}$/', $key) !== 1) {
                $this->stderr("Invalid key: IndexNow keys must be 8–128 characters of a–z, A–Z, 0–9, or '-'.\n", Console::FG_RED);
                return ExitCode::USAGE;
            }
        } else {
            $key = bin2hex(random_bytes(16));
        }

        Plugin::$plugin->siteSettings->saveWebmaster(new WebmasterSettings(
            siteId: $site->id,
            indexNowKey: $key,
        ));

        $proofUrl = rtrim((string) $site->getBaseUrl(), '/') . '/' . $key . '.txt';
        $this->stdout(sprintf("IndexNow key saved for site '%s':\n  %s\n", $site->handle, $key), Console::FG_GREEN);
        $this->stdout("Ownership-proof URL:\n  {$proofUrl}\n");

        if ($this->configuredKeyFor($site) !== null) {
            $this->stdout(sprintf(
                "\nNote: site '%s' has an IndexNow key in config/beacon.php, which takes precedence. "
                . "The saved key won't take effect until that config entry is removed.\n",
                $site->handle,
            ), Console::FG_YELLOW);
        }

        if (!Plugin::$plugin->settings->get()->indexNowEnabled) {
            $this->stdout("\nNote: auto-submit is off — set `indexNowEnabled` to true to submit on entry save.\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * The IndexNow key configured for a site in `config/beacon.php`, or null
     * when none is set.
     */
    private function configuredKeyFor(Site $site): ?string
    {
        $config = Craft::$app->getConfig()->getConfigFromFile('beacon');
        $map = is_array($config) && is_array($config['indexNowKeys'] ?? null) ? $config['indexNowKeys'] : null;
        return $map !== null ? Strings::trimToNull($map[$site->handle] ?? null) : null;
    }

    /**
     * Submit one URL on behalf of a specific site (`--site=<handle>`, default = primary).
     */
    public function actionSubmit(string $url): int
    {
        if (($site = $this->resolveSiteOrPrimary()) === null) {
            return ExitCode::CONFIG;
        }
        if (Plugin::$plugin->indexNow->submitUrl($url, $site)) {
            $this->stdout("Submitted to IndexNow: {$url}\n", Console::FG_GREEN);
            return ExitCode::OK;
        }
        $this->stderr("Submission skipped or failed for {$url} — see Craft log for details.\n", Console::FG_YELLOW);
        return ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Submit every live, non-noindex entry from a single section.
     */
    public function actionSection(string $handle): int
    {
        if (($site = $this->resolveSiteOrPrimary()) === null) {
            return ExitCode::CONFIG;
        }
        return $this->submitQuery(
            Entry::find()->section($handle)->siteId($site->id)->status(Entry::STATUS_LIVE),
            $site,
            "section '{$handle}' on site '{$site->handle}'",
        );
    }

    /**
     * Submit every live, non-noindex entry across every section the site exposes.
     */
    public function actionAll(): int
    {
        if (($site = $this->resolveSiteOrPrimary()) === null) {
            return ExitCode::CONFIG;
        }
        return $this->submitQuery(
            Entry::find()->siteId($site->id)->status(Entry::STATUS_LIVE),
            $site,
            "all live entries on site '{$site->handle}'",
        );
    }

    /**
     * @param EntryQuery<int, Entry> $query
     */
    private function submitQuery(EntryQuery $query, Site $site, string $label): int
    {
        $urls = [];
        foreach ($query->each(500) as $entry) {
            assert($entry instanceof Entry);
            $url = SeoFieldReader::indexableUrl($entry);
            if ($url !== null) {
                $urls[] = $url;
            }
        }

        if ($urls === []) {
            $this->stdout("No eligible URLs for {$label}.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $chunks = array_chunk($urls, 10000);
        $chunkCount = count($chunks);
        $okChunks = array_sum(array_map(
            fn(array $chunk): int => Plugin::$plugin->indexNow->submit($chunk, $site) ? 1 : 0,
            $chunks,
        ));

        $total = count($urls);
        if ($okChunks === $chunkCount) {
            $this->stdout(sprintf("Submitted %d URL(s) for %s.\n", $total, $label), Console::FG_GREEN);
            return ExitCode::OK;
        }

        $this->stderr(sprintf(
            "Submitted %d URL(s) for %s — %d/%d chunks reported success. See Craft log for details.\n",
            $total,
            $label,
            $okChunks,
            $chunkCount,
        ), Console::FG_YELLOW);
        return ExitCode::UNSPECIFIED_ERROR;
    }
}
