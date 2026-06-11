<?php

namespace anvildev\beacon\helpers;

use DateTime;

/**
 * Centralizes the `Y-m-d H:i:s` DB-timestamp idiom so every call site uses
 * the same timezone-handling convention.
 */
final class Db
{
    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function cutoff(int $amount, string $unit = 'days'): string
    {
        return (new DateTime("-{$amount} {$unit}"))->format('Y-m-d H:i:s');
    }

    public static function future(int $amount, string $unit = 'seconds'): string
    {
        return (new DateTime("+{$amount} {$unit}"))->format('Y-m-d H:i:s');
    }
}
