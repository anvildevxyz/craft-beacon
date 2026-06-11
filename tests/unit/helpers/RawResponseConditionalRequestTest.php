<?php

namespace anvildev\beacon\tests\unit\helpers;

use DateTime;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use yii\web\Request;

class RawResponseConditionalRequestTest extends TestCase
{
    public function testIfNoneMatchExactEtagReturnsTrue(): void
    {
        $request = new Request();
        $request->getHeaders()->set('If-None-Match', '"abc123"');

        $result = $this->invokeIsNotModified($request, '"abc123"', null);
        $this->assertTrue($result);
    }

    public function testWeakEtagIsAcceptedAsMatch(): void
    {
        $request = new Request();
        $request->getHeaders()->set('If-None-Match', 'W/"abc123"');

        $result = $this->invokeIsNotModified($request, '"abc123"', null);
        $this->assertTrue($result);
    }

    public function testIfNoneMatchMismatchOverridesIfModifiedSince(): void
    {
        $request = new Request();
        $request->getHeaders()->set('If-None-Match', '"other"');
        $request->getHeaders()->set('If-Modified-Since', 'Wed, 01 Jan 2099 00:00:00 GMT');

        $lastModified = new DateTime('2026-01-01T00:00:00+00:00');
        $result = $this->invokeIsNotModified($request, '"abc123"', $lastModified);
        $this->assertFalse($result);
    }

    public function testIfModifiedSinceWithoutEtagReturnsTrueWhenFresh(): void
    {
        $request = new Request();
        $request->getHeaders()->set('If-Modified-Since', 'Wed, 01 Jan 2026 00:00:00 GMT');

        $lastModified = new DateTime('2026-01-01T00:00:00+00:00');
        $result = $this->invokeIsNotModified($request, '"abc123"', $lastModified);
        $this->assertTrue($result);
    }

    private function invokeIsNotModified(Request $request, string $etag, ?DateTime $lastModified): bool
    {
        $ref = new ReflectionClass(\anvildev\beacon\helpers\RawResponse::class);
        $method = $ref->getMethod('isNotModified');
        $method->setAccessible(true);

        /** @var bool $result */
        $result = $method->invoke(null, $request, $etag, $lastModified);
        return $result;
    }
}

