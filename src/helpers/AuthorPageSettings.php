<?php

namespace anvildev\beacon\helpers;

use yii\db\Query;

/**
 * Reads author-page settings from the settings row without going through
 * {@see \anvildev\beacon\Plugin}, so {@see \anvildev\beacon\elements\AuthorElement}
 * can resolve URIs without importing the plugin hub.
 */
final class AuthorPageSettings
{
    public static function enabled(): bool
    {
        $row = self::row();
        return $row !== null && (bool) ($row['authorPagesEnabled'] ?? false);
    }

    public static function uriPrefix(): string
    {
        $row = self::row();
        $clean = trim((string) ($row['authorPagesUriPrefix'] ?? ''), "/ \t\n\r\0\x0B");

        return $clean !== '' ? $clean : 'authors';
    }

    /**
     * @return array{authorPagesEnabled?: bool|string|int, authorPagesUriPrefix?: string|null}|null
     */
    private static function row(): ?array
    {
        /** @var array{authorPagesEnabled?: bool|string|int, authorPagesUriPrefix?: string|null}|false $row */
        $row = (new Query())
            ->select(['authorPagesEnabled', 'authorPagesUriPrefix'])
            ->from('{{%beacon_settings}}')
            ->where(['id' => 1])
            ->one();

        return is_array($row) ? $row : null;
    }
}
