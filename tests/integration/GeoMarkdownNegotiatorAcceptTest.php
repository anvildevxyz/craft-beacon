<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\helpers\Http;
use anvildev\beacon\web\GeoMarkdownNegotiator;
use Craft;
use craft\test\TestCase;
use ReflectionClass;

/**
 * @group requires-craft
 */
class GeoMarkdownNegotiatorAcceptTest extends TestCase
{
    public function testClientPrefersMarkdownForMarkdownAcceptHeader(): void
    {
        if (!class_exists(\Craft::class) || Craft::$app === null) {
            $this->markTestSkipped('Requires initialized Craft app/request context.');
        }

        $request = Http::request();
        $headers = $request->getHeaders();
        $originalAccept = $headers->get('Accept');

        try {
            $headers->set('Accept', 'text/markdown, text/html;q=0.8');
            $this->assertTrue($this->invokeClientPrefersMarkdown());
        } finally {
            if (is_string($originalAccept) && $originalAccept !== '') {
                $headers->set('Accept', $originalAccept);
            } else {
                $headers->remove('Accept');
            }
        }
    }

    public function testClientPrefersMarkdownReturnsFalseForNonMarkdownAcceptHeader(): void
    {
        if (!class_exists(\Craft::class) || Craft::$app === null) {
            $this->markTestSkipped('Requires initialized Craft app/request context.');
        }

        $request = Http::request();
        $headers = $request->getHeaders();
        $originalAccept = $headers->get('Accept');

        try {
            $headers->set('Accept', 'text/html,application/xhtml+xml');
            $this->assertFalse($this->invokeClientPrefersMarkdown());
        } finally {
            if (is_string($originalAccept) && $originalAccept !== '') {
                $headers->set('Accept', $originalAccept);
            } else {
                $headers->remove('Accept');
            }
        }
    }

    private function invokeClientPrefersMarkdown(): bool
    {
        $ref = new ReflectionClass(GeoMarkdownNegotiator::class);
        $method = $ref->getMethod('clientPrefersMarkdown');
        $method->setAccessible(true);

        /** @var bool $result */
        $result = $method->invoke(null);
        return $result;
    }
}
