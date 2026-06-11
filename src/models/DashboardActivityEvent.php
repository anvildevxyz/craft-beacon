<?php

namespace anvildev\beacon\models;

use anvildev\beacon\enums\DashboardActivityType;
use DateTime;

final readonly class DashboardActivityEvent
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public DashboardActivityType $type,
        public DateTime $when,
        public array $data,
    ) {
    }
}
