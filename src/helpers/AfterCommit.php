<?php

namespace anvildev\beacon\helpers;

use Craft;
use yii\db\Connection;

/**
 * Defers a side effect until the outermost open database transaction commits.
 *
 * Craft wraps element saves in a transaction, so anything Beacon does from
 * `EVENT_AFTER_SAVE` runs while the row it reacts to is still invisible to
 * other connections and still able to disappear. Two things in Beacon care:
 * cache invalidation (a concurrent reader would otherwise re-cache the
 * pre-write value against the already-bumped tag) and queue pushes (a rollback
 * would take unrelated, already-committed work's jobs with it).
 *
 * Callbacks are deduped by key for the lifetime of the transaction, so a bulk
 * operation that registers the same follow-up for every row still runs it once.
 */
final class AfterCommit
{
    /** @var array<string, true> */
    private static array $registered = [];

    /**
     * Runs $callback once the current transaction commits — or immediately,
     * when there is no transaction open.
     *
     * On rollback the callback is dropped, not run: whatever it was following
     * up on did not happen.
     */
    public static function run(string $key, callable $callback): void
    {
        $db = Craft::$app->getDb();

        if ($db->getTransaction() === null) {
            $callback();
            return;
        }

        if (isset(self::$registered[$key])) {
            return;
        }
        self::$registered[$key] = true;

        $onCommit = null;
        $onRollback = null;

        $detach = static function() use ($db, $key, &$onCommit, &$onRollback): void {
            $db->off(Connection::EVENT_COMMIT_TRANSACTION, $onCommit);
            $db->off(Connection::EVENT_ROLLBACK_TRANSACTION, $onRollback);
            unset(self::$registered[$key]);
        };

        $onCommit = static function() use ($detach, $callback): void {
            $detach();
            $callback();
        };
        $onRollback = static function() use ($detach): void {
            $detach();
        };

        $db->on(Connection::EVENT_COMMIT_TRANSACTION, $onCommit);
        $db->on(Connection::EVENT_ROLLBACK_TRANSACTION, $onRollback);
    }
}
