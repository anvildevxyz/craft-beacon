<?php

namespace anvildev\beacon\tracking\providers;

use anvildev\beacon\enums\TrackingPlacement;
use anvildev\beacon\enums\TrackingProvider;
use anvildev\beacon\tracking\AbstractBeaconTrackingProvider;
use Craft;

final class MatomoProvider extends AbstractBeaconTrackingProvider
{
    public function getHandle(): string
    {
        return TrackingProvider::Matomo->value;
    }

    public function getDisplayName(): string
    {
        return Craft::t('beacon', 'tracking.matomo.matomo');
    }

    public function validateConfig(array $config): array
    {
        $errors = [];
        $url = (string)($config['matomoUrl'] ?? '');
        if (!str_starts_with($url, 'https://') || !filter_var($url, FILTER_VALIDATE_URL)) {
            $errors['matomoUrl'] = Craft::t('beacon', 'tracking.matomo.matomo.url.must.valid.https');
        }
        $siteId = (int)($config['siteId'] ?? 0);
        if ($siteId < 1) {
            $errors['siteId'] = Craft::t('beacon', 'tracking.matomo.site.id.must.positive.integer');
        }
        return $errors;
    }

    public function getFixedPlacements(): ?array
    {
        return null;
    }

    // Matomo emits the same snippet regardless of placement; $placement is
    // accepted to satisfy the interface contract but intentionally unused.
    public function render(array $config, TrackingPlacement $placement): string
    {
        $url = htmlspecialchars(rtrim((string)($config['matomoUrl'] ?? ''), '/'), ENT_QUOTES, 'UTF-8');
        // siteId is cast to int before output — no further escaping needed.
        $siteId = (int)($config['siteId'] ?? 0);
        return <<<HTML
<!-- Matomo -->
<script>var _paq=window._paq=window._paq||[];_paq.push(['trackPageView']);_paq.push(['enableLinkTracking']);(function(){var u="{$url}/";_paq.push(['setTrackerUrl', u+'matomo.php']);_paq.push(['setSiteId', '{$siteId}']);var d=document,g=d.createElement('script'),s=d.getElementsByTagName('script')[0];g.async=true;g.src=u+'matomo.js';s.parentNode.insertBefore(g,s);})();</script>
<!-- End Matomo -->
HTML;
    }
}
