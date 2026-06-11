<?php

namespace anvildev\beacon\tests\bench;

use anvildev\beacon\variables\BeaconVariable;
use anvildev\beacon\widgets\BotActivityWidget;
use anvildev\beacon\Plugin;
use craft\elements\Entry;

/**
 * Craft-bootstrapped benchmark placeholders for full-path verification.
 * These only run in an environment where Craft is fully initialized.
 *
 * @group requires-craft
 * @BeforeMethods({"setUp"})
 * @Iterations(10)
 * @Revs(5)
 */
class CraftBootstrapBench
{
    private ?Entry $entry = null;

    public function setUp(): void
    {
        $entry = Entry::find()->one();
        $this->entry = $entry instanceof Entry ? $entry : null;
    }

    public function benchMetaRenderHead(): void
    {
        if ($this->entry === null) {
            return;
        }
        $variable = new BeaconVariable();
        (string) $variable->head();
    }

    public function benchSitemapRender(): void
    {
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return;
        }
        $plugin->sitemap->renderUrlset([
            ['url' => 'https://example.test/a', 'lastmod' => '2026-01-01T00:00:00+00:00'],
        ], 0.8, 'weekly');
    }

    public function benchBotActivityWidgetQuery(): void
    {
        $widget = new BotActivityWidget();
        $widget->range = '7d';
        $widget->getBodyHtml();
    }
}
