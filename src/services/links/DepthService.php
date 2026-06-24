<?php

namespace anvildev\beacon\services\links;

use anvildev\beacon\records\LinkRecord;
use Craft;
use craft\base\Component;
use craft\elements\Entry;

class DepthService extends Component
{
    /**
     * Calculate click depths for all elements reachable from the homepage.
     *
     * BFS operates within a single site's link graph — beacon_links records
     * are scoped by sourceSiteId/targetSiteId. Links to other sites are stored
     * as external links and excluded from the traversal.
     *
     * @return array{depths: array<int,int>, unreachable: int[], noHomepage: bool}
     */
    public function calculateDepths(int $siteId): array
    {
        $homepage = $this->findHomepage($siteId);
        if ($homepage === null) {
            return ['depths' => [], 'unreachable' => [], 'noHomepage' => true];
        }

        $graph = $this->buildGraph($siteId);
        $depths = $this->bfs((int) $homepage->id, $graph);

        // Collect all known element IDs from the graph (sources + targets)
        $allIds = array_keys($graph);
        foreach ($graph as $targets) {
            foreach ($targets as $targetId) {
                $allIds[] = $targetId;
            }
        }
        $allIds = array_unique($allIds);

        $unreachable = [];
        foreach ($allIds as $id) {
            if (!isset($depths[$id])) {
                $unreachable[] = $id;
            }
        }

        return ['depths' => $depths, 'unreachable' => $unreachable, 'noHomepage' => false];
    }

    /**
     * Resolve the homepage entry for the given site.
     *
     * Each site can serve its root URL differently, so this method tries
     * several strategies (first match wins):
     *
     *   1. Craft's standard __home__ URI (most common)
     *   2. The site's base URL path as a URI (e.g. /en → "en", /fr → "fr")
     *   3. An entry with an empty URI
     */
    public function findHomepage(int $siteId): ?Entry
    {
        // 1. Standard Craft homepage URI
        $element = Craft::$app->getElements()->getElementByUri('__home__', $siteId);
        if ($element instanceof Entry) {
            return $element;
        }

        // 2. Resolve from the site's base URL path
        $site = Craft::$app->getSites()->getSiteById($siteId);
        if ($site !== null) {
            $baseUrl = $site->getBaseUrl();
            if ($baseUrl !== null) {
                $path = trim((string) (parse_url($baseUrl, PHP_URL_PATH) ?: '/'), '/');
                if ($path !== '') {
                    $element = Craft::$app->getElements()->getElementByUri($path, $siteId);
                    if ($element instanceof Entry) {
                        return $element;
                    }
                }
            }
        }

        // 3. Empty URI
        $element = Craft::$app->getElements()->getElementByUri('', $siteId);
        if ($element instanceof Entry) {
            return $element;
        }

        return null;
    }

    /**
     * BFS from a root node through a directed adjacency list.
     * Returns a map of elementId => depth. Handles cycles safely.
     *
     * @param array<int, int[]> $graph
     * @return array<int, int>
     */
    public function bfs(int $rootId, array $graph): array
    {
        $depths = [$rootId => 0];
        $queue = [$rootId];

        while ($queue !== []) {
            $current = array_shift($queue);
            $neighbours = $graph[$current] ?? [];
            $nextDepth = $depths[$current] + 1;

            foreach ($neighbours as $neighbour) {
                if (!isset($depths[$neighbour])) {
                    $depths[$neighbour] = $nextDepth;
                    $queue[] = $neighbour;
                }
            }
        }

        return $depths;
    }

    /**
     * Build a directed adjacency list from internal links for the given site.
     *
     * @return array<int, int[]>
     */
    private function buildGraph(int $siteId): array
    {
        $records = LinkRecord::find()
            ->where([
                'sourceSiteId' => $siteId,
                'isExternal' => false,
            ])
            ->andWhere(['not', ['targetElementId' => null]])
            ->all();

        $graph = [];
        foreach ($records as $record) {
            /** @var LinkRecord $record */
            $sourceId = (int) $record->sourceElementId;
            $targetId = (int) $record->targetElementId;

            if (!isset($graph[$sourceId])) {
                $graph[$sourceId] = [];
            }

            if (!in_array($targetId, $graph[$sourceId], true)) {
                $graph[$sourceId][] = $targetId;
            }
        }

        return $graph;
    }
}
