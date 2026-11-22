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

    /**
     * Aborts the current web request with a 404.
     *
     * Craft 6 serves plugin site routes from the adapter's Yii fallback
     * (`HandleYiiSiteRouteFallback::run()`), which does not catch
     * `yii\web\NotFoundHttpException`. That exception then reaches Laravel as
     * an unhandled error and the HTTP status is 500. Symfony's
     * `NotFoundHttpException` is what Laravel renders as a 404. Craft 5 still
     * expects Yii's exception, so the thrown class depends on which Craft is
     * installed.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException on Craft 6
     * @throws \yii\web\NotFoundHttpException on Craft 5
     *
     * @author Anvil Dev
     * @since 1.6.0
     */
    public static function abortNotFound(string $message = ''): never
    {
        if (class_exists(\CraftCms\Cms\Element\Queries\ElementQuery::class)) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException($message);
        }

        throw new \yii\web\NotFoundHttpException($message);
    }

    /**
     * Aborts the current web request with an HTTP status other than 404.
     *
     * Same Craft 6 reason as {@see abortNotFound()}: a Yii HTTP exception
     * thrown from a site route becomes a Laravel 500. 404 stays on
     * {@see abortNotFound()} so Craft 5 still throws `NotFoundHttpException`
     * (the cache warmer distinguishes that class from other failures).
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException on Craft 6
     * @throws \yii\web\HttpException on Craft 5
     *
     * @author Anvil Dev
     * @since 1.6.0
     */
    public static function abort(int $status, string $message = ''): never
    {
        if ($status === 404) {
            self::abortNotFound($message);
        }

        if (class_exists(\CraftCms\Cms\Element\Queries\ElementQuery::class)) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException($status, $message);
        }

        throw new \yii\web\HttpException($status, $message);
    }
}
