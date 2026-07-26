<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\Plugin;
use anvildev\beacon\records\ShortLinkRecord;
use Craft;
use craft\test\TestCase;

/**
 * Beacon's 404 handler skips the short-link throttle and lookup entirely when
 * no short link exists, so the gate has to be both correct and actually cached.
 */
final class ShortLinkExistenceGateTest extends TestCase
{
    protected function _before(): void
    {
        parent::_before();
        Craft::$app->getCache()->flush();
    }

    protected function _after(): void
    {
        Craft::$app->getCache()->flush();
        parent::_after();
    }

    public function testReportsFalseWhenNoShortLinksExist(): void
    {
        ShortLinkRecord::deleteAll();

        $this->assertFalse(Plugin::getInstance()->shortLinks->anyExist());
    }

    /**
     * A `false` result has to survive in the cache. Yii signals a cache miss by
     * returning `false`, so storing the boolean directly would silently make
     * every call re-query — the opposite of the point.
     */
    public function testFalseResultIsActuallyCached(): void
    {
        ShortLinkRecord::deleteAll();
        $this->assertFalse(Plugin::getInstance()->shortLinks->anyExist());

        $cached = Craft::$app->getCache()->get('beacon.shortLinks.any');
        $this->assertNotFalse($cached, 'a negative result must be cached, not re-queried every request');
        $this->assertSame(0, (int) $cached);
    }

    public function testCreatingTheFirstShortLinkFlipsTheGate(): void
    {
        ShortLinkRecord::deleteAll();
        $this->assertFalse(Plugin::getInstance()->shortLinks->anyExist());

        $element = new \anvildev\beacon\elements\ShortLinkElement();
        $element->slug = 'gate-probe';
        $element->destination = '/somewhere';
        $element->statusCode = 301;
        Craft::$app->getElements()->saveElement($element);

        $this->assertTrue(
            Plugin::getInstance()->shortLinks->anyExist(),
            'the first short link must invalidate the cached gate immediately',
        );

        Craft::$app->getElements()->deleteElement($element, true);
    }
}
