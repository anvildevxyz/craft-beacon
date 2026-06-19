<?php

namespace anvildev\beacon\services;

use anvildev\beacon\records\McpTokenRecord;
use DateTime;
use yii\base\Component;

/**
 * Issues, lists, revokes, and resolves MCP API tokens. A token is shown to the
 * operator exactly once at creation; only its SHA-256 hash is stored, so the DB
 * never holds a usable credential. Resolution hashes the presented token and
 * looks it up in constant time via the unique-hash index.
 */
class McpTokenService extends Component
{
    public const TOKEN_PREFIX = 'bcn_';

    /**
     * Generate a fresh opaque token string. Pure (no DB) so it's unit-testable.
     */
    public static function generateToken(): string
    {
        return self::TOKEN_PREFIX . bin2hex(random_bytes(24));
    }

    /**
     * Stable, deterministic hash used for storage + lookup. Pure.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Display prefix (safe to store/show) identifying a token without revealing it.
     */
    public static function prefixOf(string $token): string
    {
        return substr($token, 0, 12);
    }

    /**
     * Create a token for a user and return [record, plaintextToken]. The
     * plaintext is the ONLY time the caller can see the usable credential.
     *
     * @return array{0:McpTokenRecord,1:string}
     */
    public function issue(string $name, int $userId): array
    {
        $token = self::generateToken();
        $record = new McpTokenRecord();
        $record->name = $name !== '' ? $name : 'MCP token';
        $record->userId = $userId;
        $record->tokenHash = self::hashToken($token);
        $record->tokenPrefix = self::prefixOf($token);
        $record->enabled = true;
        $record->save();

        return [$record, $token];
    }

    /**
     * Resolve a presented token to its enabled record, or null. Updates
     * `lastUsedAt` on a hit.
     */
    public function resolve(string $token): ?McpTokenRecord
    {
        if ($token === '') {
            return null;
        }
        $record = McpTokenRecord::findOne(['tokenHash' => self::hashToken($token), 'enabled' => true]);
        if ($record === null) {
            return null;
        }
        $record->lastUsedAt = (new DateTime())->format('Y-m-d H:i:s');
        $record->save(false, ['lastUsedAt']);
        return $record;
    }

    public function revoke(int $id): bool
    {
        $record = McpTokenRecord::findOne($id);
        if ($record === null) {
            return false;
        }
        return (bool) $record->delete();
    }

    /**
     * @return list<McpTokenRecord>
     */
    public function all(): array
    {
        /** @var list<McpTokenRecord> $rows */
        $rows = McpTokenRecord::find()->orderBy(['dateCreated' => SORT_DESC])->all();
        return $rows;
    }
}
