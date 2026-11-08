<?php

namespace anvildev\beacon\tests\unit\mcp;

use PHPUnit\Framework\TestCase;

class CraftToolsTest extends TestCase
{
    public function testCraftToolsDelegateToTheExistingHandlers(): void
    {
        $tools = $this->source(dirname(__DIR__, 3) . '/src/mcp/CraftTools.php');
        $service = $this->source(dirname(__DIR__, 3) . '/src/services/McpService.php');
        $plugin = $this->source(dirname(__DIR__, 3) . '/src/Plugin.php');

        $this->assertStringContainsString('class_exists(\\craft\\services\\Mcp::class)', $plugin);
        $this->assertStringContainsString('EVENT_REGISTER_CAPABILITY_TYPES', $plugin);
        $this->assertStringContainsString('CraftTools::class', $plugin);

        preg_match_all("/name: '(beacon_[^']+)'/", $tools, $names);
        $this->assertCount(10, $names[1]);
        $this->assertSame(10, substr_count($tools, '$this->call('));
        $this->assertStringContainsString('function callTool(', $service);
        $this->assertStringNotContainsString('PublicMcp', $tools);

        foreach ([
            'beacon.get_entry_seo',
            'beacon.get_geo_score',
            'beacon.list_redirects',
            'beacon.list_404s',
            'beacon.get_llms_txt',
            'beacon.create_redirect',
            'beacon.delete_redirect',
            'beacon.set_entry_meta',
            'beacon.recompute_geo_score',
            'beacon.submit_indexnow',
        ] as $handler) {
            $this->assertStringContainsString("'$handler'", $tools);
            $this->assertStringContainsString("'$handler'", $service);
        }

        $this->assertSame(5, substr_count($tools, 'readOnlyHint: true'));
        $this->assertSame(5, substr_count($tools, 'destructiveHint: true'));
    }

    private function source(string $path): string
    {
        $source = file_get_contents($path);
        if (!is_string($source)) {
            throw new \RuntimeException("Could not read {$path}");
        }

        return $source;
    }
}
