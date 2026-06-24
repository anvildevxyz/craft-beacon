<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\models\Settings;
use anvildev\beacon\services\AiClient;
use anvildev\beacon\services\ai\AiProviderInterface;
use anvildev\beacon\services\AiVisibilityService;
use PHPUnit\Framework\TestCase;

class AiVisibilityServiceTest extends TestCase
{
    public function testEvaluatePromptDetectsCitation(): void
    {
        $service = $this->serviceReturning('The best option is documented at https://acme.com/start.');
        $result = $service->evaluatePrompt('What is the best tool?', 'anthropic', ['acme.com'], ['rival.com']);

        $this->assertTrue($result->cited);
        $this->assertSame('anthropic', $result->engine);
        $this->assertSame('What is the best tool?', $result->promptText);
        $this->assertSame(['https://acme.com/start'], $result->matchedUrls);
        $this->assertNotSame('', $result->answerExcerpt);
    }

    public function testEvaluatePromptRecordsCompetitorMention(): void
    {
        $service = $this->serviceReturning('Honestly, most people just use rival.com for that.');
        $result = $service->evaluatePrompt('Which tool?', 'openai', ['acme.com'], ['rival.com']);

        $this->assertFalse($result->cited);
        $this->assertSame(['rival.com'], $result->competitorMentions);
    }

    public function testEvaluatePromptTruncatesLongAnswerToExcerpt(): void
    {
        $long = str_repeat('word ', 400); // ~2000 chars
        $service = $this->serviceReturning($long);
        $result = $service->evaluatePrompt('q', 'anthropic', ['acme.com'], []);

        $this->assertLessThanOrEqual(801, mb_strlen($result->answerExcerpt));
        $this->assertStringEndsWith('…', $result->answerExcerpt);
    }

    public function testDormantWhenDisabled(): void
    {
        $service = new AiVisibilityService();
        $service->aiClient = $this->configuredClient('anything');
        // Provider is configured, but the panel toggle is off → inactive.
        $this->assertFalse($service->isActive(new Settings(aiVisibilityEnabled: false)));
    }

    public function testInactiveWhenProviderUnconfiguredEvenIfEnabled(): void
    {
        $service = new AiVisibilityService();
        $service->aiClient = new AiClient(); // no provider, no booted app → not configured
        $this->assertFalse($service->isActive(new Settings(aiVisibilityEnabled: true)));
    }

    public function testEnginesDefaultToConfiguredProvider(): void
    {
        $service = new AiVisibilityService();
        $this->assertSame(['anthropic'], $service->engines(new Settings(aiProvider: 'anthropic')));
        $this->assertSame(['openai'], $service->engines(new Settings(aiProvider: 'openai')));
    }

    public function testEnginesHonourExplicitList(): void
    {
        $service = new AiVisibilityService();
        $settings = new Settings(aiVisibilityEngines: ['chatgpt', ' perplexity ', '']);
        $this->assertSame(['chatgpt', 'perplexity'], $service->engines($settings));
    }

    private function serviceReturning(string $answer): AiVisibilityService
    {
        $service = new AiVisibilityService();
        $service->aiClient = $this->configuredClient($answer);
        return $service;
    }

    private function configuredClient(string $answer): AiClient
    {
        $client = new AiClient();
        $client->provider = new class ($answer) implements AiProviderInterface {
            public function __construct(private readonly string $answer)
            {
            }

            public function complete(string $system, string $user, array $options = []): string
            {
                return $this->answer;
            }
        };
        return $client;
    }
}
