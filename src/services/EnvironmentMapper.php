<?php

namespace anvildev\beacon\services;

use anvildev\beacon\enums\Environment;
use Craft;

final class EnvironmentMapper
{
    /** Canonical alias → Environment; unknown values fall back to Production. */
    private const MAP = [
        'production' => Environment::Production,
        'live' => Environment::Production,
        'prod' => Environment::Production,
        'staging' => Environment::Staging,
        'stage' => Environment::Staging,
        'preprod' => Environment::Staging,
        'test' => Environment::Staging,
        'dev' => Environment::Dev,
        'development' => Environment::Dev,
        'local' => Environment::Dev,
    ];

    public static function canonicalize(string $env): Environment
    {
        return self::MAP[strtolower(trim($env))] ?? Environment::Production;
    }

    /**
     * Resolves the active environment for the current request from
     * `Craft::$app->env`. To behave like a different environment, change
     * Craft's `CRAFT_ENVIRONMENT` — Beacon does not maintain a separate
     * override.
     */
    public static function resolveActive(): Environment
    {
        return self::canonicalize((string) Craft::$app->env);
    }
}
