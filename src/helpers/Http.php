<?php

namespace anvildev\beacon\helpers;

use Craft;
use craft\web\Request as WebRequest;
use craft\web\Response as WebResponse;
use yii\base\InvalidCallException;

/**
 * Typed accessors for the current web request/response.
 *
 * Beacon's controllers and web responders only run inside a web application,
 * so the request and response are always their `craft\web\*` types — but
 * `Craft::$app->getRequest()` / `getResponse()` are typed as the web|console
 * union. These assert the web context (rather than hiding a console instance
 * behind a silent fallback) and narrow the type for callers.
 */
class Http
{
    public static function request(): WebRequest
    {
        $request = Craft::$app->getRequest();
        if (!$request instanceof WebRequest) {
            throw new InvalidCallException('A web request is required in this context.');
        }
        return $request;
    }

    public static function response(): WebResponse
    {
        $response = Craft::$app->getResponse();
        if (!$response instanceof WebResponse) {
            throw new InvalidCallException('A web response is required in this context.');
        }
        return $response;
    }
}
