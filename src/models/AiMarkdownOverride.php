<?php

namespace anvildev\beacon\models;

use anvildev\beacon\helpers\Strings;

/**
 * Per-entry AI Markdown overrides stored in the Beacon SEO field.
 *
 * @phpstan-type AiMarkdownOverrideArray array{
 *     enabled: 'inherit'|'include'|'exclude',
 *     customFrontMatter: array<string, string>,
 * }
 */
final class AiMarkdownOverride
{
    public const ENABLED_INHERIT = 'inherit';
    public const ENABLED_INCLUDE = 'include';
    public const ENABLED_EXCLUDE = 'exclude';

    /**
     * @param 'inherit'|'include'|'exclude' $enabled
     * @param array<string, string> $customFrontMatter
     */
    public function __construct(
        /** @var 'inherit'|'include'|'exclude' */
        public readonly string $enabled = self::ENABLED_INHERIT,
        public readonly array $customFrontMatter = [],
    ) {
    }

    /**
     * @param array<string, mixed> $group Raw `aiMarkdown` group from the SEO field value.
     */
    public static function fromSeoFieldGroup(array $group): self
    {
        $raw = is_string($group['enabled'] ?? null) ? $group['enabled'] : self::ENABLED_INHERIT;

        /** @var 'inherit'|'include'|'exclude' $enabled */
        $enabled = in_array($raw, [self::ENABLED_INHERIT, self::ENABLED_INCLUDE, self::ENABLED_EXCLUDE], true)
            ? $raw
            : self::ENABLED_INHERIT;

        return new self(
            $enabled,
            Strings::parseKeyValueLines((string) ($group['customFrontMatter'] ?? '')),
        );
    }
}
