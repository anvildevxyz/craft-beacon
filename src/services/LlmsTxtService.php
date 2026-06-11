<?php

namespace anvildev\beacon\services;

use anvildev\beacon\helpers\Strings;
use yii\base\Component;

class LlmsTxtService extends Component
{
    /**
     * @param array<string, list<array{title:string, url:string, description:?string}>> $sections
     * @param array<string,?string> $trust Optional trust block lines (`policyUrl`, `licenseUrl`, `contactEmail`, …).
     */
    public function render(string $siteName, ?string $summary, array $sections, array $trust = []): string
    {
        $output = '# ' . Strings::stripLineBreaks($siteName) . "\n";
        if ($summary !== null && $summary !== '') {
            $output .= "\n> " . Strings::stripLineBreaks($summary) . "\n";
        }

        foreach ($sections as $sectionHandle => $entries) {
            $output .= "\n## " . Strings::stripLineBreaks((string) $sectionHandle) . "\n\n";
            foreach ($entries as $entry) {
                $line = '- [' . self::escapeMarkdownLinkText(Strings::stripLineBreaks($entry['title'])) . '](' . Strings::stripLineBreaks($entry['url']) . ')';
                if (!empty($entry['description'])) {
                    $line .= ': ' . Strings::stripLineBreaks($entry['description']);
                }
                $output .= "$line\n";
            }
        }

        $labels = [
            'policyUrl' => 'Site policy URL',
            'licenseUrl' => 'License URL',
            'contactEmail' => 'Contact',
            'preferredAttribution' => 'Preferred attribution',
        ];

        $lines = array_values(array_filter(
            array_map(
                static fn(string $key, mixed $value): string =>
                    isset($labels[$key]) && is_string($value) && trim($value) !== ''
                        ? '- ' . $labels[$key] . ': <' . Strings::stripLineBreaks($value) . '>'
                        : '',
                array_keys($trust),
                array_values($trust),
            ),
        ));

        if ($lines !== []) {
            $output .= "\n## Trust\n\n" . implode("\n", $lines) . "\n";
        }

        return $output;
    }

    /**
     * Escapes Markdown link-text metacharacters so a crafted entry title like
     * `Cool](javascript:alert(1)` can't terminate the surrounding `[…](url)` and
     * inject its own target. Backslash is escaped first so the substitutions
     * we then add aren't double-escaped on a subsequent pass.
     */
    private static function escapeMarkdownLinkText(string $value): string
    {
        return str_replace(['\\', ']', '['], ['\\\\', '\\]', '\\['], $value);
    }
}
