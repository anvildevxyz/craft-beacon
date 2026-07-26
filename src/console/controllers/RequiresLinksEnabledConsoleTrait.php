<?php

namespace anvildev\beacon\console\controllers;

use anvildev\beacon\Plugin;
use Craft;
use yii\console\ExitCode;

/**
 * Guards console actions behind the Links feature master toggle.
 */
trait RequiresLinksEnabledConsoleTrait
{
    /**
     * @return int|null Exit code when blocked, null when the feature is enabled
     */
    protected function exitIfLinksDisabled(): ?int
    {
        if (Plugin::$plugin->links->isEnabled()) {
            return null;
        }

        $this->stderr(Craft::t('beacon', 'links.disabled.console') . "\n");

        return ExitCode::OK;
    }
}
