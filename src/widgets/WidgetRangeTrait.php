<?php

namespace anvildev\beacon\widgets;

use Craft;

trait WidgetRangeTrait
{
    public static function rangeToHours(string $range): int
    {
        return match ($range) {
            '24h' => 24,
            '30d' => 720,
            default => 168,
        };
    }

    abstract protected function rangeSettingsTemplate(): string;

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate($this->rangeSettingsTemplate(), [
            'widget' => $this,
        ]);
    }
}
