<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\Plugin;
use Craft;
use yii\web\NotFoundHttpException;

/**
 * Guards CP actions behind the Links feature master toggle
 * ({@see \anvildev\beacon\models\LinkSettings::$enabled}).
 *
 * Call {@see requireLinksFeatureEnabled()} from {@see \craft\web\Controller::beforeAction()}.
 * {@see LinkSettingsController} intentionally does not use this trait so the
 * settings screen stays reachable while the feature is off.
 */
trait RequiresLinksEnabledTrait
{
    /**
     * @return bool false when the request was blocked (redirect or exception thrown)
     */
    protected function requireLinksFeatureEnabled(): bool
    {
        if (Plugin::$plugin->links->isEnabled()) {
            return true;
        }

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            throw new NotFoundHttpException(Craft::t('beacon', 'links.disabled'));
        }

        Craft::$app->getSession()->setNotice(Craft::t('beacon', 'links.disabled.notice'));

        if (BeaconPermissions::userCan(BeaconPermissions::EDIT_LINKS)) {
            $this->redirect('beacon/links/settings');
        } else {
            $this->redirect('beacon');
        }

        return false;
    }
}
