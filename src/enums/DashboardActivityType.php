<?php

namespace anvildev\beacon\enums;

enum DashboardActivityType: string
{
    case Redirect = 'redirect';
    case Bot = 'bot';
    case Schema = 'schema';
    case Tracking = 'tracking';
    case Sitemap = 'sitemap';
    case Llms = 'llms';
}
