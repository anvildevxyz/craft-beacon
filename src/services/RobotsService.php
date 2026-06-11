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
     */
    public function render(array $baseUserAgentRules, array $aiCrawlerRules, ?string $sitemapUrl): string
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
