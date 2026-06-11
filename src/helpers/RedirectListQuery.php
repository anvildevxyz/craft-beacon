<?php

namespace anvildev\beacon\helpers;

use anvildev\beacon\enums\RedirectType;
use anvildev\beacon\models\RedirectListFilters;

/**
 * Pure redirect-list filter → query-fragment mapper for the CP index.
 * {@see \anvildev\beacon\services\RedirectService::listForSiteFiltered()} applies
 * the returned fragments to a live {@see \anvildev\beacon\elements\RedirectElement} query.
 */
final class RedirectListQuery
{
    /**
     * @return array{
     *     where: list<array<int|string,mixed>>,
     *     status: 'enabled'|'disabled'|null,
     *     orderBy: array<string,int>
     * }
     */
    public static function resolve(RedirectListFilters $filters, ?int $staleThresholdDays): array
    {
        $where = [];

        if ($filters->q !== '') {
            $like = '%' . addcslashes($filters->q, '\\%_') . '%';
            $where[] = ['or',
                ['like', 'beacon_redirects.sourceUri', $like, false],
                ['like', 'beacon_redirects.targetUri', $like, false],
            ];
        }
        if ($filters->statusCode !== '' && ctype_digit($filters->statusCode)) {
            $where[] = ['beacon_redirects.statusCode' => (int) $filters->statusCode];
        }
        if ($filters->type !== '' && RedirectType::tryFrom($filters->type) !== null) {
            $where[] = ['beacon_redirects.type' => $filters->type];
        }
        if ($filters->source !== '') {
            $where[] = ['beacon_redirects.source' => $filters->source];
        }
        if ($filters->stale !== '' && $staleThresholdDays !== null && $staleThresholdDays > 0) {
            $cutoff = Db::cutoff($staleThresholdDays, 'days');
            $where[] = ['or',
                ['and', ['not', ['beacon_redirects.lastHit' => null]], ['<', 'beacon_redirects.lastHit', $cutoff]],
                ['and', ['beacon_redirects.lastHit' => null], ['<', 'elements.dateCreated', $cutoff]],
            ];
        }

        $status = match ($filters->enabled) {
            '1' => 'enabled',
            '0' => 'disabled',
            default => null,
        };

        return [
            'where' => $where,
            'status' => $status,
            'orderBy' => self::sortOrder($filters->sort),
        ];
    }

    /**
     * @return array<string,int>
     */
    public static function sortOrder(string $sort): array
    {
        return match ($sort) {
            'hits_asc' => ['beacon_redirects.hits' => SORT_ASC, 'beacon_redirects.sourceUri' => SORT_ASC],
            'updated_desc' => ['elements.dateUpdated' => SORT_DESC, 'beacon_redirects.hits' => SORT_DESC],
            'updated_asc' => ['elements.dateUpdated' => SORT_ASC, 'beacon_redirects.sourceUri' => SORT_ASC],
            'source_uri_asc' => ['beacon_redirects.sourceUri' => SORT_ASC],
            'source_uri_desc' => ['beacon_redirects.sourceUri' => SORT_DESC],
            'last_hit_desc' => ['beacon_redirects.lastHit' => SORT_DESC, 'beacon_redirects.hits' => SORT_DESC],
            'manual' => ['beacon_redirects.sortOrder' => SORT_ASC, 'beacon_redirects.id' => SORT_ASC],
            default => ['beacon_redirects.hits' => SORT_DESC, 'elements.dateUpdated' => SORT_DESC],
        };
    }
}
