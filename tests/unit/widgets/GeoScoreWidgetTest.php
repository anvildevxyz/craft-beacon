<?php

namespace anvildev\beacon\tests\unit\widgets;

use anvildev\beacon\widgets\GeoScoreWidget;
use PHPUnit\Framework\TestCase;

class GeoScoreWidgetTest extends TestCase
{
    public function testDisplayNameIsHumanLabel(): void
    {
        // Without a Craft bootstrap, Craft::t() returns the semantic key.
        $this->assertSame('widgets.geoScore.geo.content.score', GeoScoreWidget::displayName());
    }

    public function testIconReturnsCraftIconKey(): void
    {
        // Icon must be a Craft-recognised key (a string), not a SVG path.
        // The chip + chart-line icons ship with Craft 5; we use chart-line
        // because the widget body emphasises distribution over progress.
        $this->assertSame('chart-line', GeoScoreWidget::icon());
    }

    public function testTitleMatchesDisplayName(): void
    {
        $widget = new GeoScoreWidget();
        $this->assertSame(GeoScoreWidget::displayName(), $widget->getTitle());
    }
}
