<?php

namespace anvildev\beacon\helpers;

/**
 * Registry of well-known social platforms Beacon's "Social profiles" UI
 * exposes as first-class fields. Each platform has:
 *
 *  - `key`: stable handle stored in the DB and used in Twig (`socials.twitter`)
 *  - `label`: display name in the CP
 *  - `placeholder`: example URL shown under the input
 *  - `handleHosts`: hostnames whose last path segment is the user handle
 *    (used by `parseHandle()` to derive `@yourhandle` from a profile URL).
 *
 * Keep this list small and curated. For everything else, admins can paste
 * URLs into the "Additional profiles" textarea — those go straight to
 * Schema.org `sameAs` without per-platform metadata.
 *
 * @phpstan-type SocialPlatform array{key:string, label:string, placeholder:string, handleHosts:list<string>}
 */
final class SocialPlatforms
{
    /**
     * @var list<SocialPlatform>
     */
    private const PLATFORMS = [
        ['key' => 'twitter',   'label' => 'X / Twitter',  'placeholder' => 'https://x.com/yourhandle',                'handleHosts' => ['x.com', 'twitter.com']],
        ['key' => 'facebook',  'label' => 'Facebook',     'placeholder' => 'https://facebook.com/yourpage',           'handleHosts' => ['facebook.com', 'fb.com']],
        ['key' => 'linkedin',  'label' => 'LinkedIn',     'placeholder' => 'https://linkedin.com/company/yourco',     'handleHosts' => []],
        ['key' => 'instagram', 'label' => 'Instagram',    'placeholder' => 'https://instagram.com/yourhandle',        'handleHosts' => ['instagram.com']],
        ['key' => 'youtube',   'label' => 'YouTube',      'placeholder' => 'https://youtube.com/@yourchannel',        'handleHosts' => ['youtube.com']],
        ['key' => 'pinterest', 'label' => 'Pinterest',    'placeholder' => 'https://pinterest.com/yourpage',          'handleHosts' => ['pinterest.com']],
        ['key' => 'tiktok',    'label' => 'TikTok',       'placeholder' => 'https://tiktok.com/@yourhandle',          'handleHosts' => ['tiktok.com']],
        ['key' => 'github',    'label' => 'GitHub',       'placeholder' => 'https://github.com/yourorg',              'handleHosts' => ['github.com']],
        ['key' => 'mastodon',  'label' => 'Mastodon',     'placeholder' => 'https://mastodon.social/@yourhandle',     'handleHosts' => []],
        ['key' => 'bluesky',   'label' => 'Bluesky',      'placeholder' => 'https://bsky.app/profile/yourhandle',     'handleHosts' => ['bsky.app']],
        ['key' => 'threads',   'label' => 'Threads',      'placeholder' => 'https://threads.net/@yourhandle',         'handleHosts' => ['threads.net']],
    ];

    /** @var array<string,SocialPlatform>|null */
    private static ?array $byKeyMemo = null;

    /**
     * @return list<SocialPlatform>
     */
    public static function all(): array
    {
        return self::PLATFORMS;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::PLATFORMS, 'key');
    }

    /**
     * Extract the bare handle (no leading `@`, no slashes) from a profile URL.
     * Returns null when the URL doesn't match a known host or has no
     * recoverable handle segment.
     */
    public static function parseHandle(string $platformKey, string $url): ?string
    {
        if (($url = trim($url)) === '') {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        $platform = (self::$byKeyMemo ??= array_column(self::PLATFORMS, null, 'key'))[$platformKey] ?? null;
        if ($platform === null || !in_array($host, $platform['handleHosts'], true)) {
            return null;
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ($path === '') {
            return null;
        }
        // Strip a leading `@` for X / TikTok / YouTube-style handles so the stored handle is always bare.
        $last = ($pos = strrpos($path, '/')) !== false ? substr($path, $pos + 1) : $path;
        return ltrim($last, '@') ?: null;
    }
}
