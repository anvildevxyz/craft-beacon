<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\elements\ShortLinkElement;
use craft\test\TestCase;

/**
 * The short-link destination is emitted verbatim as a `Location:` header in the
 * 404 listener, so it must pass the same allowlist as redirect targets. These
 * tests guard against an open-redirect / phishing regression.
 *
 * @group requires-craft
 */
class ShortLinkElementTest extends TestCase
{
    /**
     * @dataProvider unsafeDestinations
     */
    public function testRejectsUnsafeDestination(string $destination): void
    {
        $link = new ShortLinkElement();
        $link->destination = $destination;

        $this->assertFalse(
            $link->validate(['destination']),
            "Expected '$destination' to fail validation",
        );
        $this->assertTrue($link->hasErrors('destination'));
    }

    /** @return array<string, array{0:string}> */
    public static function unsafeDestinations(): array
    {
        return [
            'protocol-relative' => ['//evil.example'],
            'javascript scheme' => ['javascript:alert(document.cookie)'],
            'data scheme' => ['data:text/html,<script>alert(1)</script>'],
            'crlf header injection' => ["/ok\r\nSet-Cookie: x=1"],
        ];
    }

    /**
     * @dataProvider safeDestinations
     */
    public function testAcceptsSafeDestination(string $destination): void
    {
        $link = new ShortLinkElement();
        $link->destination = $destination;

        $link->validate(['destination']);
        $this->assertFalse(
            $link->hasErrors('destination'),
            "Expected '$destination' to pass validation",
        );
    }

    /** @return array<string, array{0:string}> */
    public static function safeDestinations(): array
    {
        return [
            'root-relative' => ['/landing'],
            'nested path' => ['/sale/black-friday'],
            'https absolute' => ['https://example.com/promo'],
        ];
    }
}
