<?php

namespace anvildev\beacon\tests\unit\migrations;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard: `Install::seedDefaultBots()` is the only bot list a fresh
 * plugin install ever sees — numbered migrations (including the one that
 * introduced `AiBotsService::DEFAULT_BOTS`'s `purpose` field) are marked as
 * already-applied, not replayed, when a plugin installs for the first time
 * (see `craft\base\Plugin::install()`). A hardcoded second copy of the bot
 * list here previously went stale the moment `DEFAULT_BOTS` changed, so a
 * fresh install got the old 12-bot list with no `purpose` at all. Guards
 * against that silently coming back by asserting the seed method defers to
 * the single source of truth instead of listing bots itself.
 */
class InstallSeedsBotsFromSingleSourceTest extends TestCase
{
    public function testSeedDefaultBotsDefersToAiBotsServiceConstant(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/migrations/Install.php');
        $this->assertIsString($source);

        $this->assertStringContainsString(
            'AiBotsService::DEFAULT_BOTS',
            $source,
            'seedDefaultBots() must iterate AiBotsService::DEFAULT_BOTS, not a second hardcoded bot list',
        );
        $this->assertStringNotContainsString(
            "'Claude-Web'",
            $source,
            'Install.php must not carry its own stale copy of the curated bot list',
        );
    }
}
