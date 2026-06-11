<?php

namespace anvildev\beacon\enums;

enum RenderCacheType: string
{
    case Sitemap = 'sitemap';
    case LlmsTxt = 'llmstxt';
    case Humans = 'humans';
    case Ads = 'ads';
    case Schemamap = 'schemamap';
}
