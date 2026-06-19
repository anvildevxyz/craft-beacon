<?php

namespace anvildev\beacon\services;

use anvildev\beacon\helpers\Strings;
use yii\base\Component;

/**
 * @phpstan-import-type UserAgentRule from \anvildev\beacon\models\RobotsSettings
 */
class RobotsService extends Component
{
    /**
     * @param list<UserAgentRule> $baseUserAgentRules
     * @param list<array{bot:string, allow?:list<string>, disallow?:list<string>}> $aiCrawlerRules
     * @param list<string> $contentSignalLines Pre-rendered Content Signals Policy lines (see AiUsageService).
     */
    public function render(array $baseUserAgentRules, array $aiCrawlerRules, ?string $sitemapUrl, array $contentSignalLines = []): string
    {
        $baseBlocks = array_map(
            fn(array $rule): string => $this->renderBlock(
                userAgent: (string) $rule['userAgent'],
                allow: $rule['allow'] ?? [],
                disallow: $rule['disallow'] ?? [],
            ),
            $baseUserAgentRules,
        );

        $aiBlocks = array_map(
            fn(array $rule): string => $this->renderBlock(
                userAgent: (string) $rule['bot'],
                allow: $rule['allow'] ?? [],
                disallow: $rule['disallow'] ?? [],
            ),
            $aiCrawlerRules,
        );

        $output = implode("\n", [...$baseBlocks, ...$aiBlocks]);

        if ($contentSignalLines !== []) {
            $output .= "\n" . implode("\n", array_map(
                static fn(string $line): string => Strings::stripLineBreaks($line),
                $contentSignalLines,
            )) . "\n";
        }

        if ($sitemapUrl !== null && $sitemapUrl !== '') {
            $output .= "\nSitemap: " . Strings::stripLineBreaks($sitemapUrl) . "\n";
        } else {
            $output .= "\n";
        }

        return $output;
    }

    /**
     * @param list<string> $allow
     * @param list<string> $disallow
     */
    private function renderBlock(string $userAgent, array $allow, array $disallow): string
    {
        $lines = ['User-agent: ' . Strings::stripLineBreaks($userAgent)];
        foreach ($allow as $path) {
            $lines[] = 'Allow: ' . Strings::stripLineBreaks($path);
        }
        foreach ($disallow as $path) {
            $lines[] = 'Disallow: ' . Strings::stripLineBreaks($path);
        }
        return implode("\n", $lines) . "\n";
    }
}
