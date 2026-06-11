<?php

namespace Helper;

use Codeception\Module;
use Codeception\TestInterface;

class Integration extends Module
{
    /**
     * Runs after the Craft module's _before (which boots the test app and opens
     * the per-test transaction). Under the CLI SAPI the app flags its request as
     * a console request, so Beacon's front-end-only code (tracking render,
     * BeaconVariable head/body, Http::request()/response()) would treat every
     * test as an admin/console context. Pin a front-end web request so those
     * paths run as they would on the site — the Craft module's transaction still
     * rolls back anything they write.
     */
    public function _before(TestInterface $test): void
    {
        $request = \Craft::$app->getRequest();
        $request->setIsConsoleRequest(false);
        if (method_exists($request, 'setIsCpRequest')) {
            $request->setIsCpRequest(false);
        }
    }
}
