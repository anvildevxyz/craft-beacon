<?php

namespace anvildev\beacon\helpers;

use craft\db\Query;

/**
 * Read-only queries against the 404 log table without going through
 * {@see \anvildev\beacon\Plugin}, so GraphQL resolvers can share the CP
 * ordering without importing the plugin hub.
 *
 * @phpstan-import-type Redirect404LogRow from \anvildev\beacon\types\ArrayShapes
 */
final class Redirect404LogQuery
{
    /**
     * @return list<Redirect404LogRow>
     */
    public static function topUnhandled(int $siteId, int $limit = 50): array
    {
        /** @var list<Redirect404LogRow> $rows */
        $rows = (new Query())
            ->select(['id', 'uri', 'hits', 'firstSeen', 'lastSeen', 'referer'])
            ->from('{{%beacon_redirect_404_log}}')
            ->where(['siteId' => $siteId, 'handled' => false])
            ->orderBy(['hits' => SORT_DESC, 'lastSeen' => SORT_DESC])
            ->limit($limit)
            ->all();

        return $rows;
    }
}
