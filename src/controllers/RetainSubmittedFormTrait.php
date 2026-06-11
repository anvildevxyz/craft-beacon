<?php

namespace anvildev\beacon\controllers;

use Craft;

/**
 * Re-renders a CP edit form with submitted (invalid) state after a failed save.
 *
 * Controllers pair this with {@see craft\web\UrlManager::setRouteParams()} so
 * the matching `actionEdit()` can inject the in-progress model/element.
 */
trait RetainSubmittedFormTrait
{
    /**
     * @param array<string, mixed> $params
     */
    private function retainSubmittedForm(array $params): null
    {
        Craft::$app->getUrlManager()->setRouteParams($params);
        return null;
    }
}
