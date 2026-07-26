<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\helpers\AfterCommit;
use Craft;
use craft\test\TestCase;
use yii\db\Connection;

/**
 * Beacon reacts to element saves from `EVENT_AFTER_SAVE`, which Craft runs
 * inside the element's own transaction. Anything that has to be true for *other*
 * connections — an invalidated cache tag, a queue row — has to wait for the
 * commit, so this is the seam the rest of that behaviour is built on.
 *
 * The suite itself runs inside a transaction, so the commit is simulated by
 * triggering the event rather than by committing for real.
 */
final class AfterCommitTest extends TestCase
{
    public function testCallbackIsDeferredWhileATransactionIsOpen(): void
    {
        $this->assertNotNull(
            Craft::$app->getDb()->getTransaction(),
            'this test only means anything inside a transaction',
        );

        $ran = 0;
        AfterCommit::run(__METHOD__, function() use (&$ran): void {
            $ran++;
        });

        $this->assertSame(0, $ran, 'the callback must not run before the commit');

        $this->fakeCommit();

        $this->assertSame(1, $ran, 'the callback must run on commit');
    }

    /**
     * A bulk save registers the same follow-up for every row it touches; the
     * point of the key is that this still costs one run.
     */
    public function testRepeatedRegistrationsCollapseToOneRun(): void
    {
        $ran = 0;
        $callback = function() use (&$ran): void {
            $ran++;
        };

        for ($i = 0; $i < 50; $i++) {
            AfterCommit::run(__METHOD__, $callback);
        }

        $this->fakeCommit();

        $this->assertSame(1, $ran);
    }

    /**
     * The handler has to detach itself, or every later commit in the process
     * would replay it.
     */
    public function testCallbackDoesNotRunAgainOnALaterCommit(): void
    {
        $ran = 0;
        AfterCommit::run(__METHOD__, function() use (&$ran): void {
            $ran++;
        });

        $this->fakeCommit();
        $this->fakeCommit();

        $this->assertSame(1, $ran);
    }

    /**
     * Whatever the callback was following up on did not happen, so it must not
     * run — and it must not linger, waiting for someone else's commit.
     */
    public function testCallbackIsDroppedOnRollback(): void
    {
        $ran = 0;
        AfterCommit::run(__METHOD__, function() use (&$ran): void {
            $ran++;
        });

        Craft::$app->getDb()->trigger(Connection::EVENT_ROLLBACK_TRANSACTION);
        $this->fakeCommit();

        $this->assertSame(0, $ran);
    }

    private function fakeCommit(): void
    {
        Craft::$app->getDb()->trigger(Connection::EVENT_COMMIT_TRANSACTION);
    }
}
