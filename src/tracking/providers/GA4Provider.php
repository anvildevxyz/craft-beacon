<?php

namespace anvildev\beacon\tracking\providers;

use anvildev\beacon\enums\TrackingPlacement;
use anvildev\beacon\enums\TrackingProvider;
use anvildev\beacon\tracking\AbstractBeaconTrackingProvider;
use Craft;

final class GA4Provider extends AbstractBeaconTrackingProvider
{
    public function getHandle(): string
    {
        return TrackingProvider::Ga4->value;
    }

    public function getDisplayName(): string
    {
        return Craft::t('beacon', 'Google Analytics 4');
    }

    public function validateConfig(array $config): array
    {
        $id = (string)($config['measurementId'] ?? '');
        if (!preg_match('/^G-[A-Z0-9]{4,}$/', $id)) {
            return ['measurementId' => Craft::t('beacon', 'Measurement ID must match format G-XXXXX.')];
        }
        return [];
    }

    public function getFixedPlacements(): ?array
    {
        return null;
    }

    // GA4 emits the same snippet regardless of placement; $placement is
    // accepted to satisfy the interface contract but intentionally unused.
    public function render(array $config, TrackingPlacement $placement): string
    {
        $id = htmlspecialchars((string)($config['measurementId'] ?? ''), ENT_QUOTES, 'UTF-8');
        return <<<HTML
<script async src="https://www.googletagmanager.com/gtag/js?id={$id}"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config', '{$id}');</script>
HTML;
    }
}
