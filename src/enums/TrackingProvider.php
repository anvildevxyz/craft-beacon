<?php

namespace anvildev\beacon\enums;

/**
 * The built-in tracking-script providers. Third-party providers registered via
 * TrackingProviderRegistry::EVENT_REGISTER_PROVIDERS are not represented here.
 */
enum TrackingProvider: string
{
    case Ga4 = 'ga4';
    case Gtm = 'gtm';
    case FacebookPixel = 'facebook_pixel';
    case Matomo = 'matomo';
    case Custom = 'custom';
}
