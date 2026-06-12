<?php

namespace anvildev\beacon\events;

use anvildev\beacon\services\scoring\PillarComputerInterface;
use yii\base\Event;

/**
 * Fired once per request the first time {@see
 * \anvildev\beacon\services\GeoScoreService::compute()} runs. Lets
 * third-party plugins register additional pillar computers; the pillar's
 * {@see PillarComputerInterface::pillar()} handle determines its weight
 * and label, falling back to the operator's
 * `Settings::$geoScorePillarWeights` override.
 *
 * @since 1.0.0
 */
final class RegisterGeoScorePillarsEvent extends Event
{
    /** @var list<PillarComputerInterface> */
    public array $pillars = [];
}
