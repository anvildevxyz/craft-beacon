<?php

namespace anvildev\beacon\tests\unit\controllers;

use anvildev\beacon\controllers\GeoScoreController;
use anvildev\beacon\helpers\BeaconPermissions;
use PHPUnit\Framework\TestCase;

class GeoScoreControllerPermissionsTest extends TestCase
{
    public function testRecomputeRequiresEditGeoScore(): void
    {
        // Mutation actions must require the dedicated edit permission so
        // operators can grant read-only triage access (view-dashboard)
        // without the ability to queue recomputes.
        $this->assertSame(
            BeaconPermissions::EDIT_GEO_SCORE,
            GeoScoreController::requiredPermissionFor('recompute'),
        );
    }

    public function testDrillDownRequiresOnlyViewDashboard(): void
    {
        // Drill-down is read-only — anyone with dashboard access can open it.
        $this->assertSame(
            BeaconPermissions::VIEW_DASHBOARD,
            GeoScoreController::requiredPermissionFor('drill-down'),
        );
    }

    public function testStatusRequiresOnlyViewDashboard(): void
    {
        // The chip-refresh poll is read-only — it only re-surfaces a score
        // already destined for the edit page, so it must not require the
        // dedicated edit permission.
        $this->assertSame(
            BeaconPermissions::VIEW_DASHBOARD,
            GeoScoreController::requiredPermissionFor('status'),
        );
    }

    public function testUnknownActionFallsBackToViewDashboard(): void
    {
        // Defensive default: any future action that forgets to declare its
        // permission gets the safest read-only one, not edit privileges.
        $this->assertSame(
            BeaconPermissions::VIEW_DASHBOARD,
            GeoScoreController::requiredPermissionFor('some-future-action'),
        );
    }
}
