<?php

namespace anvildev\beacon\tracking\providers;

use anvildev\beacon\enums\TrackingPlacement;
use anvildev\beacon\enums\TrackingProvider;
use anvildev\beacon\tracking\AbstractBeaconTrackingProvider;
use Craft;

final class GTMProvider extends AbstractBeaconTrackingProvider
{
    public function getHandle(): string
    {
        return TrackingProvider::Gtm->value;
    }

    public function getDisplayName(): string
    {
        return Craft::t('beacon', 'tracking.gtm.google.tag.manager');
    }

    public function validateConfig(array $config): array
    {
        $id = (string)($config['containerId'] ?? '');
        if (!preg_match('/^GTM-[A-Z0-9]+$/', $id)) {
            return ['containerId' => Craft::t('beacon', 'tracking.gtm.container.id.must.match.format')];
        }
        return [];
    }

    public function getFixedPlacements(): ?array
    {
        return [TrackingPlacement::Head, TrackingPlacement::BodyStart];
    }

    public function render(array $config, TrackingPlacement $placement): string
    {
        $id = htmlspecialchars((string)($config['containerId'] ?? ''), ENT_QUOTES, 'UTF-8');

        return match ($placement) {
            TrackingPlacement::Head => <<<HTML
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{$id}');</script>
<!-- End Google Tag Manager -->
HTML,
            TrackingPlacement::BodyStart => <<<HTML
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id={$id}" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
HTML,
            TrackingPlacement::BodyEnd => '',
        };
    }
}
