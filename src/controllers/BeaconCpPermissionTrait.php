<?php

namespace anvildev\beacon\controllers;

use yii\base\Action;

/**
 * Mixin for CP-only Beacon controllers.
 *
 * Each controller declares `protected const BEACON_PERMISSION = ...;` referring
 * to a {@see \anvildev\beacon\helpers\BeaconPermissions} key. The trait wires
 * {@see \craft\web\Controller::beforeAction()} to call `requirePermission()`,
 * so unauthorized requests get a 403 before the action runs.
 *
 * @method bool requirePermission(string $permissionName)
 */
trait BeaconCpPermissionTrait
{
    /** @param Action<\craft\web\Controller> $action */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $perm = static::BEACON_PERMISSION;
        if (is_string($perm) && $perm !== '') {
            $this->requirePermission($perm);
        }
        return true;
    }
}
