<?php

namespace anvildev\beacon\controllers;

/**
 * Shared guard for CP AJAX endpoints that expect POST + JSON.
 */
trait PostJsonTrait
{
    private function requirePostJson(): void
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
    }
}
