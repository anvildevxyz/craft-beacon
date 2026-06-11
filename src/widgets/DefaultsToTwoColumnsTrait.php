<?php

namespace anvildev\beacon\widgets;

use Craft;

/**
 * Defaults a freshly added widget to a two-column span so its data tables have
 * room to breathe.
 *
 * Craft's {@see \craft\services\Dashboard::saveWidget()} never persists the
 * model's `colspan` (it's managed separately via `changeWidgetColspan()`), so
 * the default has to be applied in `afterSave()` on first creation only. A
 * user's later manual resize is preserved because this only fires when the
 * widget is new and has no colspan of its own yet.
 */
trait DefaultsToTwoColumnsTrait
{
    public function afterSave(bool $isNew): void
    {
        if ($isNew && $this->id !== null && ($this->colspan === null || $this->colspan < 2)) {
            $this->colspan = 2;
            Craft::$app->getDashboard()->changeWidgetColspan((int) $this->id, 2);
        }

        parent::afterSave($isNew);
    }
}
