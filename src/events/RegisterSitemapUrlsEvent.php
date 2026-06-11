<?php

namespace anvildev\beacon\events;

use craft\models\Site;
use yii\base\Event;

/**
 * Fires while building {@see \anvildev\beacon\controllers\SitemapController} URL rows.
 *
 * Duplicate `loc` values: **extras from this event overwrite** core Beacon rows (later registration order wins among listeners too).
 *
 * @see \anvildev\beacon\Plugin::EVENT_REGISTER_SITEMAP_URLS
 *
 * @phpstan-import-type SitemapExtra from \anvildev\beacon\services\SitemapService
 */
class RegisterSitemapUrlsEvent extends Event
{
    /**
     * @var list<SitemapExtra>
     */
    private array $extras = [];

    /** @param array<string, mixed> $config */
    public function __construct(
        public Site $site,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    /**
     * @see https://www.sitemaps.org/protocol.html
     */
    public function pushUrl(
        string $loc,
        ?string $lastmod = null,
        ?string $changefreq = null,
        ?float $priority = null,
    ): void {
        $loc = trim($loc);
        if ($loc === '') {
            return;
        }

        $entry = ['loc' => $loc];
        if ($lastmod !== null && $lastmod !== '') {
            $entry['lastmod'] = $lastmod;
        }
        if ($changefreq !== null && $changefreq !== '') {
            $entry['changefreq'] = $changefreq;
        }
        if ($priority !== null) {
            $entry['priority'] = $priority;
        }

        $this->extras[] = $entry;
    }

    /**
     * @return list<SitemapExtra>
     */
    public function getExtras(): array
    {
        return $this->extras;
    }
}
