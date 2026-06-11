<?php

namespace anvildev\beacon\services;

use anvildev\beacon\helpers\IdentityTypes;
use anvildev\beacon\helpers\SeoFieldReader;
use anvildev\beacon\Plugin;
use craft\elements\Entry;
use craft\models\Site;
use yii\base\Component;

/**
 * Builds the site-level structured-data aggregation graph published at
 * `GET /beacon/schemamap.json` — Beacon's equivalent of Yoast SEO 27.1's
 * "schemamap" surface.
 *
 * Shape intent: a discovery index, not a full per-entry schema dump. AI agents
 * (and traditional crawlers) get a flat list of `{url, name, dateModified}`
 * tuples for every public entry, plus the site-level WebSite + Organization
 * identity nodes Beacon already knows how to emit. This pairs naturally with
 * `llms.txt` (markdown index) as the JSON-LD-flavoured peer.
 *
 * Bounded cost: O(entries) string concat. Heavy per-entry schema rendering
 * stays on the per-page `<head>` path, where its cost is amortised across the
 * meta-resolution cache. The map is itself cached via {@see RenderCacheService}
 * by the controller.
 */
final class SchemamapService extends Component
{
    /**
     * @return array<string,mixed>
     */
    public function buildMap(Site $site): array
    {
        $plugin = Plugin::$plugin;
        $sitemapSettings = $plugin->siteSettings->getSitemap($site->id);
        $sectionHandles = array_values(array_diff($sitemapSettings->sections, $sitemapSettings->excludeSections));

        $baseUrl = rtrim($site->getBaseUrl() ?? '', '/');
        $websiteId = $baseUrl . '/#website';

        $graph = [[
            '@type' => 'WebSite',
            '@id' => $websiteId,
            'url' => $site->getBaseUrl(),
            'name' => $site->name,
            'inLanguage' => $site->language,
        ]];

        $identity = $this->buildIdentityNode($site);
        if ($identity !== null) {
            $graph[] = $identity;
        }

        $items = [];
        if ($sectionHandles !== []) {
            $entries = Entry::find()
                ->section($sectionHandles)
                ->siteId($site->id)
                ->status(Entry::STATUS_LIVE)
                ->orderBy(['dateUpdated' => SORT_DESC])
                ->limit(null);

            foreach ($entries->each(500) as $entry) {
                assert($entry instanceof Entry);
                $url = SeoFieldReader::indexableUrl($entry);
                if ($url === null) {
                    continue;
                }
                $items[] = [
                    '@type' => 'WebPage',
                    '@id' => $url . '#webpage',
                    'url' => $url,
                    'name' => (string) $entry->title,
                    'dateModified' => $entry->dateUpdated?->format('c'),
                    'isPartOf' => ['@id' => $websiteId],
                ];
            }
        }

        $graph[] = [
            '@type' => 'Collection',
            '@id' => $baseUrl . '/beacon/schemamap.json#collection',
            'name' => sprintf('%s — schema aggregation', $site->name),
            'inLanguage' => $site->language,
            'numberOfItems' => count($items),
            'hasPart' => $items,
        ];

        return [
            '@context' => 'https://schema.org',
            '@graph' => $graph,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function buildIdentityNode(Site $site): ?array
    {
        $settings = Plugin::$plugin->settings->get();
        $name = trim(is_string($settings->organizationName) ? $settings->organizationName : '') ?: trim((string) $site->name);
        if ($name === '') {
            return null;
        }

        $node = [
            // Normalize identically to BeaconVariable's head() identity node so
            // the same entity renders the same @type in both surfaces.
            '@type' => IdentityTypes::normalize($settings->identityType),
            '@id' => rtrim($site->getBaseUrl() ?? '', '/') . '/#identity',
            'name' => $name,
            'url' => $site->getBaseUrl(),
        ];

        $rawDescription = $settings->identityAdvanced['description'] ?? '';
        $description = trim(is_string($rawDescription) ? $rawDescription : '');
        if ($description !== '') {
            $node['description'] = $description;
        }

        if ($settings->organizationLogoAssetId !== null) {
            $asset = \anvildev\beacon\helpers\Assets::findById((int) $settings->organizationLogoAssetId);
            if ($asset !== null && is_string($logoUrl = $asset->getUrl()) && $logoUrl !== '') {
                $node['logo'] = $logoUrl;
            }
        }

        $sameAs = $settings->sameAsUrls();
        if ($sameAs !== []) {
            $node['sameAs'] = $sameAs;
        }

        return $node;
    }
}
