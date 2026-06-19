<?php

namespace anvildev\beacon\helpers;

/**
 * Central vocabulary + surface mapping for AI-usage / content-licensing
 * signals. Every surface (robots meta, X-Robots-Tag, TDMRep manifest,
 * Cloudflare Content Signals in robots.txt, the Content-Usage header) derives
 * its tokens from one place here, so adding a surface — or a policy value — is
 * a single, testable change.
 *
 * Policy vocabulary (smallest set that covers the real opt-out cases):
 *  - `allow`            — no signals emitted (today's behavior; the upgrade-safe default).
 *  - `no-train`         — opt out of AI/ML training & data-mining.
 *  - `no-generative-ai` — opt out of generative-answer ("AI input") use.
 *  - `no-ai`            — opt out of both.
 */
final class AiUsagePolicy
{
    public const ALLOW = 'allow';
    public const NO_TRAIN = 'no-train';
    public const NO_GENERATIVE = 'no-generative-ai';
    public const NO_AI = 'no-ai';

    /**
     * Sentinel used by the per-entry field + per-section setting to mean
     * "fall through to the next level" rather than pin an explicit policy.
     */
    public const INHERIT = 'inherit';

    /**
     * @return list<string> The concrete (non-inherit) policy values.
     */
    public static function all(): array
    {
        return [self::ALLOW, self::NO_TRAIN, self::NO_GENERATIVE, self::NO_AI];
    }

    /**
     * Coerces any input to a known concrete policy. Unknown / empty / inherit
     * all collapse to `allow` (the safe, emit-nothing default).
     */
    public static function normalize(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, self::all(), true) ? $value : self::ALLOW;
    }

    /**
     * Treats blank / inherit as "no opinion at this level" so callers can layer
     * entry → section → global. Returns null when the level defers.
     */
    public static function normalizeOrInherit(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '' || $value === self::INHERIT) {
            return null;
        }
        return in_array($value, self::all(), true) ? $value : null;
    }

    public static function isRestrictive(string $policy): bool
    {
        return self::normalize($policy) !== self::ALLOW;
    }

    /**
     * Robots-meta / X-Robots-Tag tokens (the de-facto `noai` / `noimageai`
     * directives). These ride on the existing `robots` tag + header so both
     * surfaces stay in sync.
     *
     * @return list<string>
     */
    public static function robotsTokens(string $policy): array
    {
        return match (self::normalize($policy)) {
            self::NO_TRAIN, self::NO_AI => ['noai', 'noimageai'],
            self::NO_GENERATIVE => ['noai'],
            default => [],
        };
    }

    /**
     * Cloudflare "Content Signals" tokens for robots.txt
     * (`search` / `ai-input` / `ai-train`). We only emit the opt-outs.
     *
     * @return list<string>
     */
    public static function contentSignalTokens(string $policy): array
    {
        return match (self::normalize($policy)) {
            self::NO_TRAIN => ['ai-train=no'],
            self::NO_GENERATIVE => ['ai-input=no'],
            self::NO_AI => ['ai-train=no', 'ai-input=no'],
            default => [],
        };
    }

    /**
     * TDMRep reservation flag (W3C Text & Data Mining Reservation Protocol):
     * 1 = rights reserved, 0 = not reserved. Any restrictive policy reserves.
     */
    public static function tdmReservation(string $policy): int
    {
        return self::isRestrictive($policy) ? 1 : 0;
    }

    /**
     * Value for the experimental `Content-Usage` response header, or null when
     * the policy allows everything.
     */
    public static function contentUsage(string $policy): ?string
    {
        $tokens = match (self::normalize($policy)) {
            self::NO_TRAIN => ['ai-train=n'],
            self::NO_GENERATIVE => ['ai-input=n'],
            self::NO_AI => ['ai-train=n', 'ai-input=n'],
            default => [],
        };
        return $tokens === [] ? null : implode(', ', $tokens);
    }

    /**
     * Static path prefix derivable from a section's URI format, used to scope
     * a TDMRep `location`. Returns the leading literal segment before the first
     * `{token}` (e.g. `blog/{slug}` → `/blog/`), or null when the format opens
     * with a token (no scopable prefix) or is the homepage `__home__`.
     */
    public static function staticPrefixFromUriFormat(?string $uriFormat): ?string
    {
        $format = trim((string) $uriFormat);
        if ($format === '' || $format === '__home__') {
            return null;
        }
        $brace = strpos($format, '{');
        $literal = $brace === false ? $format : substr($format, 0, $brace);
        $literal = trim($literal, '/');
        if ($literal === '') {
            return null;
        }
        return '/' . $literal . '/';
    }
}
