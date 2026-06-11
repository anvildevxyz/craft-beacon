<?php

namespace anvildev\beacon\helpers;

/**
 * Registry of supported `meta robots` / `X-Robots-Tag` directives.
 *
 * Each entry exposes its UI shape (control type), default empty value, and a
 * formatter that turns the persisted field value into the exact token Google
 * (and friends) expect — e.g. `max-snippet:120`, `max-image-preview:large`,
 * or `noindex` for plain booleans.
 *
 * Directives are gated by a per-plugin enablement map so site authors only
 * see controls for the ones administrators have opted into.
 *
 * @phpstan-type DirectiveDef array{
 *     key: string,
 *     type: 'bool'|'int'|'enum'|'datetime',
 *     label: string,
 *     help: string,
 *     options?: list<string>,
 *     placeholder?: string,
 * }
 */
final class RobotsDirectives
{
    /**
     * Directive keys enabled by default: applied on fresh installs and whenever
     * a settings row has no stored `robotsDirectivesEnabled` value.
     *
     * @var list<string>
     */
    private const DEFAULT_ENABLED = ['noindex', 'nofollow', 'noarchive', 'nosnippet'];

    /**
     * @var list<DirectiveDef>
     */
    private const DEFINITIONS = [
        ['key' => 'noindex', 'type' => 'bool', 'label' => 'noindex', 'help' => 'Drop the page from search results.'],
        ['key' => 'nofollow', 'type' => 'bool', 'label' => 'nofollow', 'help' => 'Tell crawlers not to follow links on this page.'],
        ['key' => 'noarchive', 'type' => 'bool', 'label' => 'noarchive', 'help' => 'Disallow cached/archived copies in SERPs.'],
        ['key' => 'nosnippet', 'type' => 'bool', 'label' => 'nosnippet', 'help' => 'Suppress text snippets and video previews entirely.'],
        ['key' => 'noimageindex', 'type' => 'bool', 'label' => 'noimageindex', 'help' => 'Keep the page indexed but exclude its images.'],
        ['key' => 'notranslate', 'type' => 'bool', 'label' => 'notranslate', 'help' => 'Suppress translation offers in SERPs.'],
        ['key' => 'indexifembedded', 'type' => 'bool', 'label' => 'indexifembedded', 'help' => 'Allow indexing when content is embedded via iframe, even if `noindex` is set.'],
        [
            'key' => 'max-snippet',
            'type' => 'int',
            'label' => 'max-snippet',
            'help' => 'Maximum text snippet length in characters. Use -1 for no limit, 0 to suppress snippets. Leave blank to omit.',
            'placeholder' => 'e.g. 160 or -1',
        ],
        [
            'key' => 'max-image-preview',
            'type' => 'enum',
            'label' => 'max-image-preview',
            'help' => 'Cap the image preview size. Most rich-result surfaces require `large`.',
            'options' => ['none', 'standard', 'large'],
        ],
        [
            'key' => 'max-video-preview',
            'type' => 'int',
            'label' => 'max-video-preview',
            'help' => 'Maximum video preview length in seconds. Use -1 for no limit, 0 to suppress.',
            'placeholder' => 'e.g. 30 or -1',
        ],
        [
            'key' => 'unavailable_after',
            'type' => 'datetime',
            'label' => 'unavailable_after',
            'help' => 'Stop indexing the page after this RFC 822 / ISO 8601 datetime.',
            'placeholder' => '2026-12-31T23:59:59Z',
        ],
    ];

    /** @var array<string,DirectiveDef>|null */
    private static ?array $byKeyMemo = null;

    /**
     * @return list<DirectiveDef>
     */
    public static function definitions(): array
    {
        return self::DEFINITIONS;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::DEFINITIONS, 'key');
    }

    /**
     * Single-key lookup against {@see self::definitions()}; returns null when
     * the key isn't a known directive.
     *
     * @return DirectiveDef|null
     */
    public static function definition(string $key): ?array
    {
        return (self::$byKeyMemo ??= array_column(self::DEFINITIONS, null, 'key'))[$key] ?? null;
    }

    /**
     * Resolved enablement map: normalizes user-stored data, fills in unknown
     * keys with `false`, and falls back to {@see self::DEFAULT_ENABLED} when
     * `$stored` is null (fresh install or no settings row yet).
     *
     * @param array<string,mixed>|null $stored
     * @return array<string,bool>
     */
    public static function resolveEnabledMap(?array $stored): array
    {
        $keys = self::keys();
        if ($stored === null) {
            return array_merge(
                array_fill_keys($keys, false),
                array_fill_keys(self::DEFAULT_ENABLED, true),
            );
        }
        return array_combine($keys, array_map(fn($k) => !empty($stored[$k]), $keys));
    }

    /**
     * Directive definitions gated by an enablement map (e.g. from plugin settings).
     *
     * @param array<string,bool> $enabledMap
     * @return list<DirectiveDef>
     */
    public static function enabledDefinitions(array $enabledMap): array
    {
        return array_values(array_filter(
            self::DEFINITIONS,
            static fn(array $def): bool => !empty($enabledMap[$def['key']]),
        ));
    }

    /**
     * Default values for the SEO field's `robots` array — one entry per
     * directive, using the empty form (`false` or `''`) so unsaved entries
     * round-trip through the field's `defaults()` merge cleanly.
     *
     * @return array<string,bool|string>
     */
    public static function defaultFieldValues(): array
    {
        return array_column(
            array_map(
                fn(array $def): array => [$def['key'], $def['type'] === 'bool' ? false : ''],
                self::DEFINITIONS,
            ),
            1,
            0,
        );
    }

    /**
     * Convert a raw field value into the directive token rendered into
     * `<meta name="robots">` and `X-Robots-Tag`. Returns null when the value
     * is empty/invalid and the directive should be omitted.
     */
    public static function formatDirective(string $key, string|int|float|bool|null $value): ?string
    {
        $def = self::definition($key);
        if ($def === null) {
            return null;
        }
        return match ($def['type']) {
            'bool' => $value ? $key : null,
            'int' => self::formatInt($key, $value),
            'enum' => self::formatEnum($def, $value),
            'datetime' => self::formatDatetime($key, $value),
        };
    }

    /**
     * Resolve the active list of directive tokens for an entry, filtered by
     * the plugin-wide enablement map.
     *
     * @param array<string,mixed> $fieldValues  Raw `robots` sub-array from the SEO field value.
     * @param array<string,bool> $enabledMap   Result of `resolveEnabledMap()`.
     * @return list<string>
     */
    public static function resolveActive(array $fieldValues, array $enabledMap): array
    {
        $out = [];
        foreach (self::DEFINITIONS as $def) {
            $key = $def['key'];
            if (empty($enabledMap[$key])) {
                continue;
            }
            $token = self::formatDirective($key, $fieldValues[$key] ?? null);
            if ($token !== null) {
                $out[] = $token;
            }
        }
        return $out;
    }

    private static function formatInt(string $key, string|int|float|bool|null $value): ?string
    {
        if ($value === null || $value === '' || $value === false || !is_numeric($value)) {
            return null;
        }
        return $key . ':' . (int) $value;
    }

    /**
     * @param DirectiveDef $def
     */
    private static function formatEnum(array $def, string|int|float|bool|null $value): ?string
    {
        if (!is_string($value) || ($value = trim($value)) === '') {
            return null;
        }
        return in_array($value, $def['options'] ?? [], true) ? $def['key'] . ':' . $value : null;
    }

    private static function formatDatetime(string $key, string|int|float|bool|null $value): ?string
    {
        if (!is_string($value) || ($value = trim($value)) === '') {
            return null;
        }
        return $key . ':' . $value;
    }
}
