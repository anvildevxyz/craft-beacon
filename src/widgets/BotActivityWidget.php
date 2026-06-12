<?php

namespace anvildev\beacon\widgets;

use anvildev\beacon\helpers\Db;
use anvildev\beacon\Plugin;
use Craft;
use craft\base\Widget;

/**
 * @phpstan-type BotActivityPathRow array{path: string, hits: int}
 * @phpstan-type BotActivityBotRow array{
 *     name: string,
 *     hits: int,
 *     lastSeen: string,
 *     paths: list<BotActivityPathRow>,
 * }
 * @phpstan-type BotActivityWidgetData array{
 *     bots: list<BotActivityBotRow>,
 *     silentBots: list<string>,
 * }
 */
final class BotActivityWidget extends Widget
{
    use WidgetRangeTrait;
    use DefaultsToTwoColumnsTrait;
    use RegistersBeaconCpAssetTrait;

    public string $range = '7d';

    public static function displayName(): string
    {
        return Craft::t('beacon', 'widgets.botActivity.ai.bot.activity');
    }

    public static function icon(): ?string
    {
        return 'eye';
    }

    public function getTitle(): ?string
    {
        return Craft::t('beacon', 'widgets.botActivity.ai.bot.activity');
    }

    public function getBodyHtml(): ?string
    {
        $this->registerBeaconCpAsset();
        $since = Db::cutoff(self::rangeToHours($this->range), 'hours');
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $data = Craft::$app->getCache()->getOrSet(
            "beacon.botWidget:$siteId:{$this->range}",
            fn() => $this->loadData($siteId, $since),
            60,
        );

        return Craft::$app->getView()->renderTemplate('beacon/_widgets/bot-activity', [
            'bots' => $data['bots'],
            'silentBots' => $data['silentBots'],
            'range' => $this->range,
        ]);
    }

    protected function rangeSettingsTemplate(): string
    {
        return 'beacon/_widgets/bot-activity-settings';
    }

    /** @return BotActivityWidgetData */
    private function loadData(int $siteId, string $since): array
    {
        $db = Craft::$app->getDb();

        // [[…]]-quote camelCase identifiers AND aliases: Postgres folds
        // unquoted names to lowercase (breaks both the columns and the
        // `lastSeen` result key); MySQL is indifferent.
        $bots = $db->createCommand(
            'SELECT [[botName]], COUNT(*) AS [[hits]], MAX([[hitAt]]) AS [[lastSeen]]
             FROM {{%beacon_bot_log}}
             WHERE [[siteId]] = :siteId AND [[hitAt]] >= :since
             GROUP BY [[botName]]
             ORDER BY [[hits]] DESC
             LIMIT 10',
            ['siteId' => $siteId, 'since' => $since],
        )->queryAll();

        $result = [];
        foreach ($bots as $b) {
            /** @var array{botName: string, hits: int|string, lastSeen: string} $b */
            $paths = $db->createCommand(
                'SELECT [[path]], COUNT(*) AS [[hits]]
                 FROM {{%beacon_bot_log}}
                 WHERE [[siteId]] = :siteId AND [[botName]] = :botName AND [[hitAt]] >= :since
                 GROUP BY [[path]]
                 ORDER BY [[hits]] DESC
                 LIMIT 5',
                ['siteId' => $siteId, 'since' => $since, 'botName' => $b['botName']],
            )->queryAll();

            $result[] = [
                'name' => (string) $b['botName'],
                'hits' => (int) $b['hits'],
                'lastSeen' => (string) $b['lastSeen'],
                'paths' => array_map(
                    /** @param array{path: string, hits: int|string} $p */
                    static fn(array $p): array => ['path' => (string) $p['path'], 'hits' => (int) $p['hits']],
                    $paths,
                ),
            ];
        }

        $silent = array_values(array_diff(
            array_map(static fn($b) => $b->name, Plugin::$plugin->botRegistry->getBots()),
            array_column($result, 'name'),
        ));

        return ['bots' => $result, 'silentBots' => $silent];
    }
}
