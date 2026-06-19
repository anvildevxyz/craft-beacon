<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\services\AiUsageService;
use PHPUnit\Framework\TestCase;

class AiUsageServiceTest extends TestCase
{
    private function service(): AiUsageService
    {
        return new AiUsageService();
    }

    public function testResolvePolicyEntryBeatsSectionBeatsGlobal(): void
    {
        $svc = $this->service();
        $this->assertSame('no-ai', $svc->resolvePolicy('no-ai', 'no-train', 'allow'));
        $this->assertSame('no-train', $svc->resolvePolicy('', 'no-train', 'allow'));
        $this->assertSame('no-train', $svc->resolvePolicy('inherit', 'no-train', 'allow'));
        $this->assertSame('no-generative-ai', $svc->resolvePolicy(null, null, 'no-generative-ai'));
        $this->assertSame('allow', $svc->resolvePolicy('', '', null));
    }

    public function testHasAnyRestrictive(): void
    {
        $svc = $this->service();
        $this->assertFalse($svc->hasAnyRestrictive('allow', []));
        $this->assertFalse($svc->hasAnyRestrictive('allow', ['blog' => 'allow']));
        $this->assertTrue($svc->hasAnyRestrictive('no-train', []));
        $this->assertTrue($svc->hasAnyRestrictive('allow', ['blog' => 'no-ai']));
    }

    public function testContentSignalLinesEmptyWhenAllAllow(): void
    {
        $this->assertSame([], $this->service()->contentSignalLines('allow', ['blog' => 'allow']));
    }

    public function testContentSignalLinesFromGlobalPolicy(): void
    {
        $lines = $this->service()->contentSignalLines('no-ai');
        $this->assertContains('User-agent: *', $lines);
        $this->assertContains('Content-Signal: ai-train=no, ai-input=no', $lines);
    }

    public function testContentSignalLinesEchoSectionScopesAsComments(): void
    {
        $lines = $this->service()->contentSignalLines(
            'allow',
            ['blog' => 'no-train'],
            ['blog' => '/blog/'],
        );
        // Global allow → no directive line, but the restrictive section is noted.
        $this->assertStringNotContainsString('Content-Signal: ai-train=no, ai-input=no', implode("\n", $lines));
        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('/blog/ → ai-train=no', implode("\n", $lines));
    }

    public function testTdmRepManifestEmptyWhenAllAllow(): void
    {
        $this->assertSame([], $this->service()->tdmRepManifest('allow', ['blog' => 'allow']));
    }

    public function testTdmRepManifestGlobalAndSectionEntries(): void
    {
        $manifest = $this->service()->tdmRepManifest(
            'no-train',
            ['blog' => 'no-ai', 'noprefix' => 'no-train'],
            ['blog' => '/blog/'],
            'https://example.com/ai-policy',
        );

        // Global '/' entry + the section with a derivable prefix; the
        // prefix-less section is skipped (can't be located).
        $locations = array_column($manifest, 'location');
        $this->assertContains('/', $locations);
        $this->assertContains('/blog/', $locations);
        $this->assertNotContains('section: noprefix', $locations);

        foreach ($manifest as $entry) {
            $this->assertSame(1, $entry['tdm-reservation']);
            $this->assertArrayHasKey('tdm-policy', $entry);
            $this->assertSame('https://example.com/ai-policy', $entry['tdm-policy'] ?? null);
        }
    }

    public function testTdmRepManifestOmitsPolicyUrlWhenBlank(): void
    {
        $manifest = $this->service()->tdmRepManifest('no-train');
        $this->assertCount(1, $manifest);
        $this->assertArrayNotHasKey('tdm-policy', $manifest[0]);
    }
}
