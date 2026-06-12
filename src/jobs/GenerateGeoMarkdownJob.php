<?php

namespace anvildev\beacon\jobs;

use anvildev\beacon\integrations\CommerceIntegration;
use anvildev\beacon\Plugin;
use Craft;
use craft\queue\BaseJob;

/**
 * Async generation of GEO Markdown for a single element (Entry or Product).
 *
 * Pre-generated rows are written to {{%beacon_geo_markdown}} via
 * {@see \anvildev\beacon\services\GeoMarkdownStore::put()}. On-demand
 * requests served by the controllers/negotiator hit the same store, so
 * pre-generation just warms what the on-demand path would write anyway.
 */
class GenerateGeoMarkdownJob extends BaseJob
{
    public int $siteId = 0;
    public int $elementId = 0;

    /**
     * @param \craft\queue\QueueInterface $queue
     */
    public function execute($queue): void
    {
        if ($this->siteId <= 0 || $this->elementId <= 0) {
            return;
        }

        $element = CommerceIntegration::findLiveMarkdownElement('id', $this->elementId, $this->siteId);
        $markdown = $element !== null ? Plugin::$plugin->geoMarkdownExport->exportElement($element) : null;

        if ($markdown === null) {
            Plugin::$plugin->geoMarkdownStore->clear($this->siteId, $this->elementId);
            return;
        }

        Plugin::$plugin->geoMarkdownStore->put($this->siteId, $this->elementId, $markdown);
        Craft::info(
            sprintf('GEO export pre-generated for elementId=%d siteId=%d', $this->elementId, $this->siteId),
            'beacon',
        );
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('beacon', 'jobs.geoMarkdown.generating.geo.markdown.element', ['id' => $this->elementId]);
    }
}
