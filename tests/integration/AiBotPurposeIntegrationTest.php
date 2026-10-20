<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\controllers\AiCrawlersController;
use anvildev\beacon\enums\BotPurpose;
use anvildev\beacon\models\AiBot;
use anvildev\beacon\models\AiCrawlerRule;
use anvildev\beacon\Plugin;
use Craft;
use craft\test\TestCase;
use craft\web\TemplateResponseBehavior;
use yii\web\BadRequestHttpException;

/**
 * Issue #49 — the AI-bot `purpose` field, the refreshed default bot list, and
 * the purpose-scoped allow/disallow bulk action.
 *
 * @group requires-craft
 */
class AiBotPurposeIntegrationTest extends TestCase
{
    public function testResetDefaultsAssignsCorrectPurposeToEveryBot(): void
    {
        $this->deleteAllBots();
        $plugin = Plugin::getInstance();

        $reinserted = $plugin->aiBots->resetDefaults();
        $this->assertSame(16, $reinserted);

        $byName = [];
        foreach ($plugin->aiBots->getAllBots() as $bot) {
            $byName[$bot->name] = $bot;
        }

        $this->assertSame(BotPurpose::Training, $byName['GPTBot']->purpose);
        $this->assertSame(BotPurpose::SearchIndex, $byName['OAI-SearchBot']->purpose);
        $this->assertSame(BotPurpose::UserAgent, $byName['ChatGPT-User']->purpose);
        $this->assertSame(BotPurpose::UserAgent, $byName['Claude-User']->purpose);
        $this->assertSame(BotPurpose::SearchIndex, $byName['Claude-SearchBot']->purpose);
        $this->assertSame(BotPurpose::UserAgent, $byName['Perplexity-User']->purpose);
        $this->assertSame(BotPurpose::Training, $byName['Meta-ExternalAgent']->purpose);
        $this->assertSame(BotPurpose::UserAgent, $byName['Meta-ExternalFetcher']->purpose);
        $this->assertArrayNotHasKey('Claude-Web', $byName);

        foreach ($byName as $name => $bot) {
            $this->assertInstanceOf(BotPurpose::class, $bot->purpose, "{$name} should have a purpose after a fresh restore");
        }
    }

    public function testSaveBotPersistsAndClearsPurpose(): void
    {
        $plugin = Plugin::getInstance();

        $saved = $plugin->aiBots->saveBot(
            id: null,
            name: 'test-purpose-bot-' . uniqid(),
            userAgentPattern: 'TestPurposeBot/.*',
            enabled: true,
            purpose: BotPurpose::SearchIndex,
        );
        $this->assertSame(BotPurpose::SearchIndex, $plugin->aiBots->findBot($saved->id)->purpose);

        $plugin->aiBots->saveBot(
            id: $saved->id,
            name: $saved->name,
            userAgentPattern: $saved->userAgentPattern,
            enabled: true,
            purpose: null,
        );
        $this->assertNull($plugin->aiBots->findBot($saved->id)->purpose);
    }

    public function testNamesForPurposeReturnsOnlyMatchingBots(): void
    {
        $this->deleteAllBots();
        $plugin = Plugin::getInstance();
        $plugin->aiBots->resetDefaults();

        $trainingNames = $plugin->aiBots->namesForPurpose(BotPurpose::Training);

        $this->assertContains('GPTBot', $trainingNames);
        $this->assertNotContains('OAI-SearchBot', $trainingNames);
        $this->assertNotContains('ChatGPT-User', $trainingNames);
    }

    public function testApplyPolicyToBotsUpsertsAllowAndDisallowRules(): void
    {
        $plugin = Plugin::getInstance();
        $botName = 'bulk-policy-bot-' . uniqid();

        $count = $plugin->aiCrawlers->applyPolicyToBots([$botName], disallow: true);
        $this->assertSame(1, $count);

        $rule = $this->findRuleByBotName($botName);
        $this->assertSame(['/'], $rule->disallowPaths);
        $this->assertSame([], $rule->allowPaths);
        $this->assertTrue($rule->enabled);

        // Re-applying "allow" for the same bot must update the existing row, not add a second one.
        $plugin->aiCrawlers->applyPolicyToBots([$botName], disallow: false);
        $rule = $this->findRuleByBotName($botName);
        $this->assertSame([], $rule->disallowPaths);
        $this->assertSame([], $rule->allowPaths);

        $matching = array_values(array_filter(
            $plugin->aiCrawlers->getAllRules(),
            static fn(AiCrawlerRule $r): bool => $r->botName === $botName,
        ));
        $this->assertCount(1, $matching, 'applyPolicyToBots must upsert, not duplicate, a rule for the same bot name');
    }

    public function testIndexFiltersBotsByPurpose(): void
    {
        $this->deleteAllBots();
        Plugin::getInstance()->aiBots->resetDefaults();

        $controller = new AiCrawlersController('ai-crawlers', Craft::$app);
        Craft::$app->getRequest()->setQueryParams(['purpose' => 'userAgent']);
        $response = $controller->actionIndex();

        $behavior = $response->getBehavior(TemplateResponseBehavior::NAME);
        $this->assertInstanceOf(TemplateResponseBehavior::class, $behavior);
        /** @var list<AiBot> $bots */
        $bots = $behavior->variables['bots'] ?? [];
        $this->assertNotEmpty($bots);
        foreach ($bots as $bot) {
            $this->assertSame(BotPurpose::UserAgent, $bot->purpose);
        }
    }

    public function testBulkApplyPurposePolicyRejectsInvalidInput(): void
    {
        $controller = new AiCrawlersController('ai-crawlers', Craft::$app);
        $request = Craft::$app->getRequest();
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $request->setBodyParams(['purpose' => 'not-a-real-purpose', 'policy' => 'allow']);
        $this->expectException(BadRequestHttpException::class);
        $controller->actionBulkApplyPurposePolicy();
    }

    public function testBulkApplyPurposePolicyRejectsInvalidPolicy(): void
    {
        $controller = new AiCrawlersController('ai-crawlers', Craft::$app);
        $request = Craft::$app->getRequest();
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $request->setBodyParams(['purpose' => 'training', 'policy' => 'maybe']);
        $this->expectException(BadRequestHttpException::class);
        $controller->actionBulkApplyPurposePolicy();
    }

    public function testBulkApplyPurposePolicyAppliesToEveryBotOfThatPurpose(): void
    {
        $this->deleteAllBots();
        $plugin = Plugin::getInstance();
        $plugin->aiBots->resetDefaults();
        $trainingNames = $plugin->aiBots->namesForPurpose(BotPurpose::Training);
        $this->assertNotEmpty($trainingNames);

        $controller = new AiCrawlersController('ai-crawlers', Craft::$app);
        $request = Craft::$app->getRequest();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $request->setBodyParams(['purpose' => 'training', 'policy' => 'disallow']);

        $controller->actionBulkApplyPurposePolicy();

        foreach ($trainingNames as $name) {
            $rule = $this->findRuleByBotName($name);
            $this->assertNotNull($rule, "{$name} should have a rule after the bulk disallow action");
            $this->assertSame(['/'], $rule->disallowPaths);
        }
    }

    private function findRuleByBotName(string $botName): ?AiCrawlerRule
    {
        foreach (Plugin::getInstance()->aiCrawlers->getAllRules() as $rule) {
            if ($rule->botName === $botName) {
                return $rule;
            }
        }
        return null;
    }

    private function deleteAllBots(): void
    {
        foreach (Plugin::getInstance()->aiBots->getAllBots() as $bot) {
            Plugin::getInstance()->aiBots->deleteBot($bot->id);
        }
    }
}
