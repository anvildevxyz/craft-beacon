<?php

namespace anvildev\beacon\services\scoring;

/**
 * Normalised AST node emitted by {@see ContentWalker}. Walker produces a
 * flat list in document order; consumers (structural pillars) iterate and
 * track section state themselves — for example, "first paragraph under
 * each H2" is a Chunkability-pillar concern, not a walker concern.
 *
 * Type contract:
 *
 *   - `heading`   — H1–H6. `$level` populated. `$text` is the plain heading
 *                   text with tags stripped.
 *   - `paragraph` — `<p>`-equivalent block. `$text` is the plain body text.
 *   - `list`      — `<ul>`/`<ol>`. `$items` carries one string per list item.
 *   - `table`     — `<table>`. `$text` is a flattened cell concatenation
 *                   suitable for word counting.
 *   - `code`      — `<pre>`/`<code>` block. `$text` carries the code body.
 *   - `link`      — Anchor extracted from inline content. `$href` populated;
 *                   `$isInternal` is true when the link points to the current host.
 *
 * `$wordCount` is precomputed from `$text` (or item concatenation for lists)
 * so per-pillar code doesn't repeat the same tokenisation pass.
 */
final class ContentNode
{
    public const TYPE_HEADING = 'heading';
    public const TYPE_PARAGRAPH = 'paragraph';
    public const TYPE_LIST = 'list';
    public const TYPE_TABLE = 'table';
    public const TYPE_CODE = 'code';
    public const TYPE_LINK = 'link';

    /**
     * @param list<string> $items
     */
    public function __construct(
        public readonly string $type,
        public readonly ?int $level = null,
        public readonly string $text = '',
        public readonly int $wordCount = 0,
        public readonly array $items = [],
        public readonly ?string $href = null,
        public readonly bool $isInternal = false,
    ) {
    }

    /**
     * Word-count helper used by the walker and reusable by pillars that
     * compute density over arbitrary text snippets. Treats any token
     * containing at least one letter/digit/underscore as a word; punctuation-
     * only tokens (em-dashes, etc.) don't count.
     */
    public static function countWords(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        // Single preg_match_all over word-bearing tokens is faster than
        // preg_split + array_filter because it avoids building the non-matching
        // token array entirely.
        return preg_match_all('/\S*[\p{L}\p{N}_]\S*/u', $text) ?: 0;
    }
}
