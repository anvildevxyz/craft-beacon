<?php

namespace anvildev\beacon\services;

use anvildev\beacon\helpers\Db;
use anvildev\beacon\helpers\Json;
use anvildev\beacon\models\AiVisibilityResult;
use anvildev\beacon\models\BenchmarkPrompt;
use anvildev\beacon\models\Settings;
use anvildev\beacon\Plugin;
use anvildev\beacon\records\AiVisibilityResultRecord;
use anvildev\beacon\records\BenchmarkPromptRecord;
use anvildev\beacon\services\ai\AiException;
use anvildev\beacon\services\aivisibility\CitationDetector;
use Craft;
use craft\db\Query;
use craft\models\Site;
use yii\base\Component;

/**
 * Answer-engine visibility tracking. Sends per-site benchmark prompts through
 * the configured {@see AiClient} provider, detects whether each answer cites or
 * mentions the site (vs. competitors), and persists a result row per
 * (prompt, engine) for the dashboard.
 *
 * Dormant by default: {@see self::isActive()} stays false until an operator
 * enables the panel AND the AI provider is configured, so an idle install
 * schedules no jobs and makes no requests.
 */
class AiVisibilityService extends Component
{
    /** Test seam: inject an {@see AiClient} (with a fake provider) so unit tests never hit the network. */
    public ?AiClient $aiClient = null;

    private const ANSWER_EXCERPT_LENGTH = 800;

    private const SYSTEM_PROMPT =
        'You are a helpful research assistant. Answer the question directly and concisely. '
        . 'When you reference a product, tool, or organisation, include its website URL.';

    /**
     * True when the panel is enabled and the AI provider is configured.
     */
    public function isActive(?Settings $settings = null): bool
    {
        $settings ??= $this->settings();
        return $settings->aiVisibilityEnabled && $this->client()->isConfigured();
    }

    // -- Benchmark prompt CRUD ------------------------------------------------

    /**
     * @return list<BenchmarkPrompt>
     */
    public function getPrompts(int $siteId, bool $enabledOnly = false): array
    {
        $query = BenchmarkPromptRecord::find()->where(['siteId' => $siteId]);
        if ($enabledOnly) {
            $query->andWhere(['enabled' => true]);
        }
        /** @var list<BenchmarkPromptRecord> $records */
        $records = $query->orderBy(['id' => SORT_ASC])->all();
        return array_map([$this, 'toPromptModel'], $records);
    }

    public function getPrompt(int $id): ?BenchmarkPrompt
    {
        $record = BenchmarkPromptRecord::findOne($id);
        return $record !== null ? $this->toPromptModel($record) : null;
    }

    public function savePrompt(BenchmarkPrompt $prompt): bool
    {
        $record = $prompt->id !== null
            ? BenchmarkPromptRecord::findOne(['id' => $prompt->id, 'siteId' => $prompt->siteId]) ?? new BenchmarkPromptRecord()
            : new BenchmarkPromptRecord();
        $record->siteId = $prompt->siteId;
        $record->prompt = trim($prompt->prompt);
        $record->enabled = $prompt->enabled;
        if ($record->prompt === '') {
            return false;
        }
        $saved = $record->save();
        if ($saved) {
            $prompt->id = (int) $record->id;
        }
        return $saved;
    }

    public function deletePrompt(int $id, ?int $siteId = null): bool
    {
        $condition = $siteId !== null ? ['id' => $id, 'siteId' => $siteId] : ['id' => $id];
        $record = BenchmarkPromptRecord::findOne($condition);
        return $record !== null && (bool) $record->delete();
    }

    // -- Probing --------------------------------------------------------------

    /**
     * Ask one prompt of one engine and derive the citation signals. DB-free and
     * network-isolated (uses the injected/resolved {@see AiClient}), so it is the
     * unit-tested seam.
     *
     * @param list<string> $siteHosts
     * @param list<string> $competitorDomains
     * @throws AiException when the provider errors
     */
    public function evaluatePrompt(string $promptText, string $engine, array $siteHosts, array $competitorDomains): AiVisibilityResult
    {
        $answer = $this->client()->complete(self::SYSTEM_PROMPT, $promptText, ['maxTokens' => 1024]);
        $signals = (new CitationDetector())->detect($answer, $siteHosts, $competitorDomains);

        return new AiVisibilityResult(
            promptText: $promptText,
            engine: $engine,
            cited: $signals['cited'],
            domainMentioned: $signals['domainMentioned'],
            matchedUrls: $signals['matchedUrls'],
            competitorMentions: $signals['competitorMentions'],
            answerExcerpt: $this->excerpt($answer),
        );
    }

    /**
     * Run all enabled prompts for a site against all enabled engines, persist
     * the results, and return a summary. Bounded by the per-run cap.
     *
     * @return array{prompts:int, engines:int, evaluated:int, cited:int, failed:int}
     */
    public function run(int $siteId): array
    {
        $summary = ['prompts' => 0, 'engines' => 0, 'evaluated' => 0, 'cited' => 0, 'failed' => 0];
        $settings = $this->settings();
        if (!$this->isActive($settings)) {
            return $summary;
        }

        $site = Craft::$app->getSites()->getSiteById($siteId);
        if ($site === null) {
            return $summary;
        }

        $prompts = $this->getPrompts($siteId, true);
        $engines = $this->engines($settings);
        $summary['prompts'] = count($prompts);
        $summary['engines'] = count($engines);

        $siteHosts = $this->siteHosts($site);
        $competitors = $settings->aiVisibilityCompetitorDomains;
        $cap = max(1, $settings->aiVisibilityMaxPerRun);
        $runAt = Db::now();

        foreach ($prompts as $prompt) {
            foreach ($engines as $engine) {
                if ($summary['evaluated'] + $summary['failed'] >= $cap) {
                    Craft::info("AiVisibility: per-run cap ($cap) reached for site $siteId.", 'beacon');
                    return $summary;
                }
                try {
                    $result = $this->evaluatePrompt($prompt->prompt, $engine, $siteHosts, $competitors);
                    $result->siteId = $siteId;
                    $result->promptId = $prompt->id;
                    $result->runAt = $runAt;
                    $this->persistResult($result);
                    $summary['evaluated']++;
                    if ($result->cited) {
                        $summary['cited']++;
                    }
                } catch (AiException $e) {
                    $summary['failed']++;
                    Craft::warning("AiVisibility: probe failed (site $siteId, engine $engine): " . $e->getMessage(), 'beacon');
                }
            }
        }

        return $summary;
    }

    public function persistResult(AiVisibilityResult $result): void
    {
        $record = new AiVisibilityResultRecord();
        $record->siteId = $result->siteId;
        $record->promptId = $result->promptId;
        $record->promptText = $result->promptText;
        $record->engine = $result->engine;
        $record->cited = $result->cited;
        $record->domainMentioned = $result->domainMentioned;
        $record->matchedUrls = Json::encode($result->matchedUrls);
        $record->competitorMentions = Json::encode($result->competitorMentions);
        $record->answerExcerpt = $result->answerExcerpt;
        $record->runAt = $result->runAt ?? Db::now();
        $record->save(false);
    }

    // -- Dashboard reads ------------------------------------------------------

    /**
     * Citation rate (0–100) across all probes within the window.
     */
    public function citationRate(int $siteId, int $withinDays): float
    {
        $base = (new Query())
            ->from('{{%beacon_ai_visibility_results}}')
            ->where(['siteId' => $siteId])
            ->andWhere(['>=', 'runAt', Db::cutoff($withinDays, 'days')]);
        $total = (int) (clone $base)->count();
        if ($total === 0) {
            return 0.0;
        }
        $cited = (int) (clone $base)->andWhere(['cited' => true])->count();
        return round($cited / $total * 100, 1);
    }

    /**
     * Most recent probes for the drill-down, newest first.
     *
     * @return list<array{promptText:string, engine:string, cited:bool, domainMentioned:bool, matchedUrls:list<string>, competitorMentions:list<string>, answerExcerpt:string, runAt:string}>
     */
    public function latestResults(int $siteId, int $limit = 50): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = (new Query())
            ->select(['promptText', 'engine', 'cited', 'domainMentioned', 'matchedUrls', 'competitorMentions', 'answerExcerpt', 'runAt'])
            ->from('{{%beacon_ai_visibility_results}}')
            ->where(['siteId' => $siteId])
            ->orderBy(['runAt' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->all();

        return array_map(static fn(array $r): array => [
            'promptText' => (string) ($r['promptText'] ?? ''),
            'engine' => (string) ($r['engine'] ?? ''),
            'cited' => (bool) ($r['cited'] ?? false),
            'domainMentioned' => (bool) ($r['domainMentioned'] ?? false),
            'matchedUrls' => Json::decodeStringList(is_string($r['matchedUrls'] ?? null) ? $r['matchedUrls'] : null),
            'competitorMentions' => Json::decodeStringList(is_string($r['competitorMentions'] ?? null) ? $r['competitorMentions'] : null),
            'answerExcerpt' => (string) ($r['answerExcerpt'] ?? ''),
            'runAt' => (string) ($r['runAt'] ?? ''),
        ], $rows);
    }

    public function gc(int $retentionDays): int
    {
        return (int) Craft::$app->getDb()->createCommand()
            ->delete('{{%beacon_ai_visibility_results}}', ['<', 'runAt', Db::cutoff($retentionDays, 'days')])
            ->execute();
    }

    /**
     * Engine identifiers to probe. Defaults to the single configured provider
     * when the operator hasn't pinned a list. (v1 routes every engine through
     * the one configured provider; distinct per-engine credentials are a
     * documented follow-up.)
     *
     * @return list<string>
     */
    public function engines(?Settings $settings = null): array
    {
        $settings ??= $this->settings();
        $engines = array_values(array_filter(array_map(
            static fn(string $e): string => trim($e),
            $settings->aiVisibilityEngines,
        ), static fn(string $e): bool => $e !== ''));
        return $engines !== [] ? $engines : [$settings->aiProvider];
    }

    /**
     * @return list<string>
     */
    private function siteHosts(Site $site): array
    {
        $base = $site->getBaseUrl();
        if ($base === null) {
            return [];
        }
        $host = parse_url($base, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? [$host] : [];
    }

    private function excerpt(string $answer): string
    {
        $answer = trim($answer);
        if (mb_strlen($answer) <= self::ANSWER_EXCERPT_LENGTH) {
            return $answer;
        }
        return rtrim(mb_substr($answer, 0, self::ANSWER_EXCERPT_LENGTH)) . '…';
    }

    private function toPromptModel(BenchmarkPromptRecord $record): BenchmarkPrompt
    {
        return new BenchmarkPrompt(
            id: (int) $record->id,
            siteId: (int) $record->siteId,
            prompt: (string) $record->prompt,
            enabled: (bool) $record->enabled,
        );
    }

    private function client(): AiClient
    {
        if ($this->aiClient !== null) {
            return $this->aiClient;
        }
        $plugin = Plugin::$plugin;
        $this->aiClient = $plugin !== null ? $plugin->aiClient : new AiClient();
        return $this->aiClient;
    }

    private function settings(): Settings
    {
        $plugin = Plugin::$plugin;
        return $plugin !== null ? $plugin->settings->get() : new Settings();
    }
}
