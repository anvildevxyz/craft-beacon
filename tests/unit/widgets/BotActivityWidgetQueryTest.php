<?php

namespace anvildev\beacon\tests\unit\widgets;

use anvildev\beacon\widgets\BotActivityWidget;
use PHPUnit\Framework\TestCase;

class BotActivityWidgetQueryTest extends TestCase
{
    public function testRangeToHoursMapping(): void
    {
        $this->assertSame(24, BotActivityWidget::rangeToHours('24h'));
        $this->assertSame(168, BotActivityWidget::rangeToHours('7d'));
        $this->assertSame(720, BotActivityWidget::rangeToHours('30d'));
    }

    public function testUnknownRangeFallsBackTo7Days(): void
    {
        $this->assertSame(168, BotActivityWidget::rangeToHours('garbage'));
    }
}
