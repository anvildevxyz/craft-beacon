<?php

namespace anvildev\beacon\services;

use anvildev\beacon\enums\AiBotSource;
use anvildev\beacon\enums\BotPurpose;
use anvildev\beacon\helpers\Db;
use anvildev\beacon\helpers\RecordCache;
use anvildev\beacon\helpers\SafeRegex;
use anvildev\beacon\models\AiBot;
use anvildev\beacon\models\BotDefinition;
use anvildev\beacon\records\AiBotRecord;
use yii\base\Component;
use yii\base\InvalidArgumentException;

class AiBotsService extends Component
{
    use AiRecordCrudTrait;

    /**
     * Per-request memoisation. `getEnabledBots()` is the hot path: when bot
     * logging is enabled it runs on every web request (via `BotRegistry` →
     * `BotLogService::logIfBot` from the `WebApplication::EVENT_BEFORE_REQUEST`
     * listener). Each write path
     * (`saveBot`, `deleteBot`, `setBotEnabled`, `resetDefaults`) clears the
     * memo so changes are visible immediately within the same request.
     *
     * @var list<AiBot>|null
     */
    private ?array $enabledMemo = null;

    /** @var list<AiBot>|null */
    private ?array $allMemo = null;

    /**
     * Curated default bot list. Update when AI bot operators add/rename
     * crawlers. `Claude-Web` (Anthropic's retired user-agent) is gone,
     * replaced by the three crawlers Anthropic split it into; Perplexity's
     * and Meta's user-triggered fetch agents and Meta's training/indexing
     * crawlers are new additions.
     *
     * @var list<array{name:string, userAgentPattern:string, purpose:string}>
     */
    public const DEFAULT_BOTS = [
        ['name' => 'GPTBot',               'userAgentPattern' => 'GPTBot/.*',               'purpose' => BotPurpose::Training->value],
        ['name' => 'OAI-SearchBot',        'userAgentPattern' => 'OAI-SearchBot/.*',        'purpose' => BotPurpose::SearchIndex->value],
        ['name' => 'ChatGPT-User',         'userAgentPattern' => 'ChatGPT-User/.*',         'purpose' => BotPurpose::UserAgent->value],
        ['name' => 'ClaudeBot',            'userAgentPattern' => 'ClaudeBot/.*',            'purpose' => BotPurpose::Training->value],
        ['name' => 'Claude-SearchBot',     'userAgentPattern' => 'Claude-SearchBot/.*',     'purpose' => BotPurpose::SearchIndex->value],
        ['name' => 'Claude-User',          'userAgentPattern' => 'Claude-User/.*',          'purpose' => BotPurpose::UserAgent->value],
        ['name' => 'PerplexityBot',        'userAgentPattern' => 'PerplexityBot/.*',        'purpose' => BotPurpose::SearchIndex->value],
        ['name' => 'Perplexity-User',      'userAgentPattern' => 'Perplexity-User/.*',      'purpose' => BotPurpose::UserAgent->value],
        ['name' => 'Google-Extended',      'userAgentPattern' => 'Google-Extended',         'purpose' => BotPurpose::Training->value],
        ['name' => 'Bytespider',           'userAgentPattern' => 'Bytespider',              'purpose' => BotPurpose::Training->value],
        ['name' => 'Amazonbot',            'userAgentPattern' => 'Amazonbot/.*',            'purpose' => BotPurpose::SearchIndex->value],
        ['name' => 'Applebot-Extended',    'userAgentPattern' => 'Applebot-Extended',       'purpose' => BotPurpose::Training->value],
        ['name' => 'Diffbot',              'userAgentPattern' => 'Diffbot',                 'purpose' => BotPurpose::Training->value],
        ['name' => 'cohere-ai',            'userAgentPattern' => 'cohere-ai',               'purpose' => BotPurpose::Training->value],
        ['name' => 'Meta-ExternalAgent',   'userAgentPattern' => 'Meta-ExternalAgent/.*',   'purpose' => BotPurpose::Training->value],
        ['name' => 'Meta-ExternalFetcher', 'userAgentPattern' => 'Meta-ExternalFetcher/.*', 'purpose' => BotPurpose::UserAgent->value],
    ];

    /** @return list<AiBot> */
    public function getAllBots(): array
    {
        if ($this->allMemo !== null) {
            return $this->allMemo;
        }
        /** @var list<AiBotRecord> $records */
        $records = AiBotRecord::find()
            ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])
            ->all();
        return $this->allMemo = array_map(fn(AiBotRecord $r) => $this->toModel($r), $records);
    }

    /**
     * @return list<AiBot>
     */
    public function getEnabledBots(): array
    {
        if ($this->enabledMemo !== null) {
            return $this->enabledMemo;
        }

        // Cached across requests, not just memoised within one: this is the
        // single query Beacon runs on literally every front-end request, and
        // the bot list changes about as often as the plugin is configured.
        /** @var list<AiBot> $bots */
        $bots = RecordCache::remember(
            'aiBots.enabled',
            [AiBotRecord::CACHE_TAG],
            function(): array {
                /** @var list<AiBotRecord> $records */
                $records = AiBotRecord::find()
                    ->where(['enabled' => true])
                    ->orderBy(['sortOrder' => SORT_ASC])
                    ->all();
                return array_map(fn(AiBotRecord $r) => $this->toModel($r), $records);
            },
        );

        return $this->enabledMemo = $bots;
    }

    private function invalidateMemo(): void
    {
        $this->enabledMemo = null;
        $this->allMemo = null;
    }

    public function findBot(int $id): ?AiBot
    {
        $record = AiBotRecord::findOne($id);
        return $record ? $this->toModel($record) : null;
    }

    /**
     * @throws \yii\base\InvalidArgumentException when $userAgentPattern is not a safe, valid regex
     */
    public function saveBot(
        ?int $id,
        string $name,
        string $userAgentPattern,
        bool $enabled,
        string $source = AiBotSource::Custom->value,
        ?BotPurpose $purpose = null,
    ): AiBot {
        $patternError = SafeRegex::validate($userAgentPattern);
        if ($patternError !== null) {
            throw new InvalidArgumentException("Invalid bot user-agent pattern: {$patternError}");
        }
        $record = ($id ? AiBotRecord::findOne($id) : null) ?? new AiBotRecord();
        $record->name = $name;
        $record->userAgentPattern = $userAgentPattern;
        $record->enabled = $enabled;
        $record->purpose = $purpose?->value;
        if ($record->isNewRecord) {
            $record->source = $source;
            $record->sortOrder = $this->nextSortOrder(AiBotRecord::class);
            $record->dateCreated = Db::now();
        }
        $record->dateUpdated = Db::now();
        $record->save(false);
        $this->invalidateMemo();
        return $this->toModel($record);
    }

    public function deleteBot(int $id): void
    {
        $this->deleteRecordById(AiBotRecord::class, $id);
    }

    public function setBotEnabled(int $id, bool $enabled): bool
    {
        return $this->setRecordEnabled(AiBotRecord::class, $id, $enabled);
    }

    public function resetDefaults(): int
    {
        /** @var list<string> $existing */
        $existing = AiBotRecord::find()->select(['name'])->column();
        $existingSet = array_flip($existing);
        $now = Db::now();
        $reinserted = 0;

        foreach (self::DEFAULT_BOTS as $i => $row) {
            $bot = new BotDefinition($row['name'], $row['userAgentPattern']);
            if (isset($existingSet[$bot->name])) {
                continue;
            }
            $record = new AiBotRecord();
            $record->name = $bot->name;
            $record->userAgentPattern = $bot->userAgentPattern;
            $record->enabled = true;
            $record->source = AiBotSource::Default->value;
            $record->purpose = $row['purpose'];
            $record->sortOrder = $i;
            $record->dateCreated = $now;
            $record->dateUpdated = $now;
            $record->save(false);
            $reinserted++;
        }
        if ($reinserted > 0) {
            $this->invalidateMemo();
        }

        return $reinserted;
    }

    private function toModel(AiBotRecord $r): AiBot
    {
        return new AiBot(
            id: (int) $r->id,
            name: (string) $r->name,
            userAgentPattern: (string) $r->userAgentPattern,
            enabled: (bool) $r->enabled,
            source: (string) $r->source,
            sortOrder: (int) $r->sortOrder,
            purpose: $r->purpose !== null ? BotPurpose::tryFrom((string) $r->purpose) : null,
        );
    }

    /**
     * Names of every bot (default or custom) tagged with $purpose — the set a
     * purpose-scoped bulk policy action applies to.
     *
     * @return list<string>
     */
    public function namesForPurpose(BotPurpose $purpose): array
    {
        return array_values(array_map(
            static fn(AiBot $b): string => $b->name,
            array_filter($this->getAllBots(), static fn(AiBot $b): bool => $b->purpose === $purpose),
        ));
    }
}
