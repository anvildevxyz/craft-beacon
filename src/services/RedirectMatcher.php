<?php

namespace anvildev\beacon\services;

use anvildev\beacon\enums\RedirectQueryStringMode;
use anvildev\beacon\enums\RedirectType;
use anvildev\beacon\events\RegisterRedirectTypesEvent;
use anvildev\beacon\helpers\SafeRegex;
use yii\base\Component;

/**
 * @phpstan-import-type RedirectMatchResult from \anvildev\beacon\services\CustomRedirectMatcherInterface
 */
class RedirectMatcher extends Component
{
    /**
     * @event RegisterRedirectTypesEvent fires lazily on first dispatch so
     *        third parties can plug in custom matching algorithms via
     *        {@see CustomRedirectMatcherInterface}.
     */
    public const EVENT_REGISTER_REDIRECT_TYPES = 'registerRedirectTypes';

    /** @var array<string, CustomRedirectMatcherInterface>|null */
    private ?array $customTypes = null;

    /**
     * @return RedirectMatchResult
     */
    public function matchRule(
        string $typeHandle,
        string $pattern,
        string $uri,
        RedirectQueryStringMode $queryStringMode = RedirectQueryStringMode::Ignore,
    ): ?array {
        $builtin = RedirectType::tryFrom($typeHandle);
        if ($builtin !== null) {
            return $this->matches($pattern, $builtin, $uri, $queryStringMode);
        }

        $custom = $this->customMatchers()[$typeHandle] ?? null;
        if ($custom === null) {
            return null;
        }
        // Custom matchers see the URI as-is and decide their own QS policy.
        return $custom->match($pattern, $uri);
    }

    /**
     * Returns capture groups + the incoming query string (so callers can
     * append it under `preserve` mode). Captures use 1-indexed `$1`, `$2`, …
     *
     * @return RedirectMatchResult
     */
    public function matches(
        string $pattern,
        RedirectType $type,
        string $uri,
        RedirectQueryStringMode $queryStringMode = RedirectQueryStringMode::Ignore,
    ): ?array {
        [$uriPath, $uriQuery] = $this->splitPathQuery($uri);

        if ($queryStringMode === RedirectQueryStringMode::Match) {
            // sourceUri is full path?query; compare against the full incoming URI.
            $subject = $uriQuery === '' ? $uriPath : $uriPath . '?' . $uriQuery;
            $captures = $this->run($pattern, $type, $subject);
            return $captures !== null ? ['captures' => $captures, 'query' => ''] : null;
        }

        // Ignore + preserve both match on path only.
        $captures = $this->run($pattern, $type, $uriPath);
        if ($captures === null) {
            return null;
        }
        return [
            'captures' => $captures,
            'query' => $queryStringMode === RedirectQueryStringMode::Preserve ? $uriQuery : '',
        ];
    }

    /**
     * @return array<string,string>|null
     */
    private function run(string $pattern, RedirectType $type, string $subject): ?array
    {
        return match ($type) {
            RedirectType::Exact => $pattern === $subject ? [] : null,
            RedirectType::Glob => $this->matchGlob($pattern, $subject),
            RedirectType::Regex => $this->matchRegex($pattern, $subject),
        };
    }

    /**
     * @return array<string,string>|null
     */
    private function matchGlob(string $pattern, string $uri): ?array
    {
        // strtr applies longer needles first, so `\*\*` (4 chars) is rewritten before `\*` (2 chars).
        $regex = '#^' . strtr(preg_quote($pattern, '#'), ['\*\*' => '(.+)', '\*' => '([^/]+)']) . '$#';
        return SafeRegex::match($regex, $uri, $matches) === true
            ? $this->extractCaptures($matches)
            : null;
    }

    /**
     * @return array<string,string>|null
     */
    private function matchRegex(string $pattern, string $uri): ?array
    {
        $delimited = '#' . str_replace('#', '\\#', $pattern) . '#';
        return SafeRegex::match($delimited, $uri, $matches) === true
            ? $this->extractCaptures($matches)
            : null;
    }

    /**
     * @param list<string> $matches
     * @return array<string,string>
     */
    private function extractCaptures(array $matches): array
    {
        array_shift($matches); // drops the full-match entry in place; avoids an array_slice copy
        $captures = [];
        foreach ($matches as $i => $val) {
            $captures['$' . ($i + 1)] = $val;
        }
        return $captures;
    }

    /**
     * Splits `/foo/bar?utm=fb&k=v` into `['/foo/bar', 'utm=fb&k=v']`.
     * Strips any fragment defensively — a `Location:` header never carries
     * `#fragment` from the upstream URI.
     *
     * @return array{0:string,1:string}
     */
    public function splitPathQuery(string $uri): array
    {
        $hash = strpos($uri, '#');
        if ($hash !== false) {
            $uri = substr($uri, 0, $hash);
        }
        $q = strpos($uri, '?');
        return $q === false ? [$uri, ''] : [substr($uri, 0, $q), substr($uri, $q + 1)];
    }

    /**
     * @return array<string, CustomRedirectMatcherInterface>
     */
    private function customMatchers(): array
    {
        if ($this->customTypes !== null) {
            return $this->customTypes;
        }
        $event = new RegisterRedirectTypesEvent();
        $this->trigger(self::EVENT_REGISTER_REDIRECT_TYPES, $event);
        $indexed = [];
        foreach ($event->types as $t) {
            $indexed[$t->handle()] = $t;
        }
        return $this->customTypes = $indexed;
    }

    /**
     * @return array<string, string>
     */
    public function customTypeLabels(): array
    {
        return array_map(static fn(CustomRedirectMatcherInterface $m): string => $m->label(), $this->customMatchers());
    }
}
