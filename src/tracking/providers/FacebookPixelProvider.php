<?php

namespace anvildev\beacon\tracking\providers;

use anvildev\beacon\enums\TrackingPlacement;
use anvildev\beacon\enums\TrackingProvider;
use anvildev\beacon\tracking\AbstractBeaconTrackingProvider;
use Craft;

final class FacebookPixelProvider extends AbstractBeaconTrackingProvider
{
    public function getHandle(): string
    {
        return TrackingProvider::FacebookPixel->value;
    }

    public function getDisplayName(): string
    {
        return Craft::t('beacon', 'Facebook Pixel');
    }

    public function validateConfig(array $config): array
    {
        $id = (string)($config['pixelId'] ?? '');
        if (!preg_match('/^[0-9]{10,20}$/', $id)) {
            return ['pixelId' => Craft::t('beacon', 'Pixel ID must be 10–20 digits.')];
        }
        return [];
    }

    public function getFixedPlacements(): ?array
    {
        return [TrackingPlacement::Head, TrackingPlacement::BodyStart];
    }

    public function render(array $config, TrackingPlacement $placement): string
    {
        $id = htmlspecialchars((string)($config['pixelId'] ?? ''), ENT_QUOTES, 'UTF-8');

        return match ($placement) {
            TrackingPlacement::Head => <<<HTML
<!-- Meta Pixel -->
<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init', '{$id}');fbq('track', 'PageView');</script>
<!-- End Meta Pixel -->
HTML,
            TrackingPlacement::BodyStart => <<<HTML
<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id={$id}&ev=PageView&noscript=1"/></noscript>
HTML,
            TrackingPlacement::BodyEnd => '',
        };
    }
}
