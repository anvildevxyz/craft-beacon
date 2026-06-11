<?php

namespace anvildev\beacon\tracking\providers;

use anvildev\beacon\enums\TrackingPlacement;
use anvildev\beacon\enums\TrackingProvider;
use anvildev\beacon\tracking\AbstractBeaconTrackingProvider;
use Craft;

final class CustomScriptProvider extends AbstractBeaconTrackingProvider
{
    public function getHandle(): string
    {
        return TrackingProvider::Custom->value;
    }

    public function getDisplayName(): string
    {
        return Craft::t('beacon', 'Custom Script');
    }

    public function validateConfig(array $config): array
    {
        $html = trim((string)($config['html'] ?? ''));
        if ($html === '') {
            return ['html' => Craft::t('beacon', 'HTML is required.')];
        }
        return [];
    }

    public function getFixedPlacements(): ?array
    {
        return null;
    }

    public function render(array $config, TrackingPlacement $placement): string
    {
        return (string)($config['html'] ?? '');
    }
}
