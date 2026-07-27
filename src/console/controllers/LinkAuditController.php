<?php

namespace anvildev\beacon\console\controllers;

use anvildev\beacon\jobs\LinkBrokenJob;
use anvildev\beacon\records\LinkRecord;
use Craft;
use craft\console\Controller;
use yii\console\ExitCode;

/**
 * Queues HTTP audit jobs for outbound external links to detect broken targets.
 *
 * Command id: `link-audit`.
 *
 * @author Anvil
 * @since 1.0.0
 */
class LinkAuditController extends Controller
{
    use RequiresLinksEnabledConsoleTrait;

    /** @var string The only action; lets bare `beacon/link-audit` run it. */
    public $defaultAction = 'broken';

    // =========================================================================
    // Public Properties
    // =========================================================================

    /** Per-request HTTP timeout in seconds, forwarded to each job. */
    public int $timeout = 10;

    /**
     * Inter-job delay in milliseconds (forwarded to each {@see LinkBrokenJob} as
     * a usleep at the start of execute()). Kept as ms to match the
     * `httpAuditDelay` settings field — Yii's Queue::delay() only takes whole
     * seconds, which is too coarse for rate-limiting HTTP calls.
     */
    public int $delay = 200;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @param string $actionID
     * @return list<string>
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'broken') {
            $options[] = 'timeout';
            $options[] = 'delay';
        }
        return $options;
    }

    /**
     * Queues an HTTP audit job for every outbound external link.
     */
    public function actionBroken(): int
    {
        if (($exit = $this->exitIfLinksDisabled()) !== null) {
            return $exit;
        }

        // Only external links need an HTTP round trip. Internal links resolve
        // via Craft's element tables; running them through Guzzle just trips the
        // SSRF blocker and leaves httpStatus=0, which surfaces as a false
        // "broken" in the report.
        $records = LinkRecord::find()
            ->where(['not', ['targetUrl' => null]])
            ->andWhere(['ignored' => false])
            ->andWhere(['isExternal' => true])
            ->all();

        $this->stdout(sprintf("Found %d external links to audit.\n", count($records)));
        if ($records === []) {
            $this->stdout("No external links to check.\n");
            return ExitCode::OK;
        }

        $count = 0;
        foreach ($records as $record) {
            /** @var LinkRecord $record */
            Craft::$app->getQueue()->push(new LinkBrokenJob([
                'linkId' => (int) $record->id,
                'timeout' => $this->timeout,
                'delayMs' => $this->delay,
            ]));
            $count++;
        }

        $this->stdout("Queued {$count} links for HTTP audit.\n");
        return ExitCode::OK;
    }
}
