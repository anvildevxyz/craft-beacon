<?php

namespace anvildev\beacon\services;

use anvildev\beacon\enums\AiBotSource;
use anvildev\beacon\helpers\Db;
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
     * Curated default bot list. Update when AI bot operators add/rename crawlers.
     * @var list<array{name:string, userAgentPattern:string}>
     */
    public const DEFAULT_BOTS = [
        ['name' => 'GPTBot',            'userAgentPattern' => 'GPTBot/.*'],
        ['name' => 'OAI-SearchBot',     'userAgentPattern' => 'OAI-SearchBot/.*'],
        ['name' => 'ChatGPT-User',      'userAgentPattern' => 'ChatGPT-User/.*'],
        ['name' => 'ClaudeBot',         'userAgentPattern' => 'ClaudeBot/.*'],
        ['name' => 'Claude-Web',        'userAgentPattern' => 'Claude-Web/.*'],
        ['name' => 'PerplexityBot',     'userAgentPattern' => 'PerplexityBot/.*'],
        ['name' => 'Google-Extended',   'userAgentPattern' => 'Google-Extended'],
        ['name' => 'Bytespider',        'userAgentPattern' => 'Bytespider'],
        ['name' => 'Amazonbot',         'userAgentPattern' => 'Amazonbot/.*'],
        ['name' => 'Applebot-Extended', 'userAgentPattern' => 'Applebot-Extended'],
        ['name' => 'Diffbot',           'userAgentPattern' => 'Diffbot'],
        ['name' => 'cohere-ai',         'userAgentPattern' => 'cohere-ai'],
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

    /** @return list<AiBot> */
    public function getEnabledBots(): array
    {
        if ($this->enabledMemo !== null) {
            return $this->enabledMemo;
        }
        /** @var list<AiBotRecord> $records */
        $records = AiBotRecord::find()
            ->where(['enabled' => true])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();
        return $this->enabledMemo = array_map(fn(AiBotRecord $r) => $this->toModel($r), $records);
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
    public function saveBot(?int $id, string $name, string $userAgentPattern, bool $enabled, string $source = AiBotSource::Custom->value): AiBot
    {
        $patternError = SafeRegex::validate($userAgentPattern);
        if ($patternError !== null) {
            throw new InvalidArgumentException("Invalid bot user-agent pattern: {$patternError}");
        }
        $record = ($id ? AiBotRecord::findOne($id) : null) ?? new AiBotRecord();
        $record->name = $name;
        $record->userAgentPattern = $userAgentPattern;
        $record->enabled = $enabled;
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
        );
    }
}
