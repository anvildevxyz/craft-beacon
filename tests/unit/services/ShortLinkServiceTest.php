<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\services\ShortLinkService;
use PHPUnit\Framework\TestCase;

/**
 * The slug validator is the security-critical seam — it gates what an
 * editor can put in the `siteId, slug` unique key and therefore what
 * Beacon will serve from the site root. DB-touching paths (findBySlug,
 * recordClick) are covered by the integration suite.
 */
class ShortLinkServiceTest extends TestCase
{
    public function testRejectsEmptySlug(): void
    {
        $this->assertNotNull(ShortLinkService::validateSlug(''));
    }

    public function testRejectsLeadingSlash(): void
    {
        // We add the leading `/` ourselves at lookup time; double-slash
        // would yield `//slug` which Yii's router treats as host-relative.
        $this->assertNotNull(ShortLinkService::validateSlug('/foo'));
    }

    public function testRejectsOversizeSlug(): void
    {
        $this->assertNotNull(ShortLinkService::validateSlug(str_repeat('a', 129)));
    }

    public function testRejectsForbiddenCharacters(): void
    {
        // Slugs flow into the URL path — block anything that's not safe
        // there (no `?`, `#`, `&`, etc.) and anything URL-encoded would
        // hide. We use a strict allowlist instead of denylist.
        $this->assertNotNull(ShortLinkService::validateSlug('foo bar'));
        $this->assertNotNull(ShortLinkService::validateSlug('foo?bar'));
        $this->assertNotNull(ShortLinkService::validateSlug('foo#bar'));
        $this->assertNotNull(ShortLinkService::validateSlug('foo<script>'));
    }

    public function testAcceptsSafeAsciiSlugs(): void
    {
        $this->assertNull(ShortLinkService::validateSlug('blackfriday'));
        $this->assertNull(ShortLinkService::validateSlug('summer-sale-2026'));
        $this->assertNull(ShortLinkService::validateSlug('campaigns/blackfriday'));
        $this->assertNull(ShortLinkService::validateSlug('promo_2026.v3'));
    }

    public function testRejectsReservedFirstSegment(): void
    {
        // These first segments would shadow CP / framework / Beacon
        // public endpoints if we let them through.
        foreach (['admin', 'api', 'cpresources', 'actions', 'index.php', '.well-known'] as $reserved) {
            $this->assertNotNull(
                ShortLinkService::validateSlug($reserved),
                "Expected slug \"$reserved\" to be rejected",
            );
            $this->assertNotNull(
                ShortLinkService::validateSlug($reserved . '/sub'),
                "Expected slug \"$reserved/sub\" to be rejected",
            );
        }
    }

    public function testAllowsReservedNamesInDeeperSegments(): void
    {
        // Only the first segment is checked — `/promo/admin-only` is fine
        // because it doesn't shadow the CP at the root.
        $this->assertNull(ShortLinkService::validateSlug('promo/admin'));
    }
}
