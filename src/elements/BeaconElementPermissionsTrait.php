<?php

namespace anvildev\beacon\elements;

use craft\elements\User;

/**
 * Permission checks for Beacon CP element types.
 *
 * Each element declares `protected const BEACON_PERMISSION = ...` referring to
 * a {@see \anvildev\beacon\helpers\BeaconPermissions} key.
 */
trait BeaconElementPermissionsTrait
{
    public function canView(User $user): bool
    {
        return $user->admin || $user->can(static::BEACON_PERMISSION);
    }

    public function canSave(User $user): bool
    {
        return $user->admin || $user->can(static::BEACON_PERMISSION);
    }

    public function canDuplicate(User $user): bool
    {
        return $this->canSave($user);
    }

    public function canDelete(User $user): bool
    {
        return $this->canSave($user);
    }
}
