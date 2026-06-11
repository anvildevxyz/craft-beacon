<?php

namespace anvildev\beacon\gql\queries;

use anvildev\beacon\enums\RedirectType;
use anvildev\beacon\gql\types\BeaconRedirect404Type;
use anvildev\beacon\gql\types\BeaconRedirectType;
use anvildev\beacon\gql\types\BeaconShortLinkType;
use anvildev\beacon\helpers\Redirect404LogQuery;
use craft\db\Query;
use craft\gql\base\Query as BaseQuery;
use craft\gql\GqlEntityRegistry;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;

/**
 * Read-only GraphQL queries for the redirect tables. Headless storefronts
 * use these to render a sitemap-style "old URL" map, or to expose the 404
 * triage queue to an editorial dashboard outside the CP.
 *
 * Scoped by two schema components:
 *  - `beaconRedirects:read`     — gates `beaconRedirects` / `beaconRedirect`
 *  - `beaconRedirect404s:read`  — gates `beaconRedirect404s` (potentially
 *                                  sensitive; referrer URLs may include
 *                                  internal paths)
 *
 * @phpstan-import-type BeaconRedirect404GqlRow from \anvildev\beacon\gql\GqlArrayShapes
 * @phpstan-import-type BeaconRedirectGqlRow from \anvildev\beacon\gql\GqlArrayShapes
 * @phpstan-import-type BeaconShortLinkGqlRow from \anvildev\beacon\gql\GqlArrayShapes
 * @phpstan-import-type Redirect404LogRow from \anvildev\beacon\types\ArrayShapes
 */
class BeaconRedirectQueries extends BaseQuery
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getQueries(bool $checkToken = true): array
    {
        $queries = [];

        if (!$checkToken || GqlHelper::canSchema('beaconRedirects', 'read')) {
            $queries['beaconRedirects'] = [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(BeaconRedirectType::getType()))),
                'args' => [
                    'siteId' => ['type' => Type::int(), 'description' => 'Filter by siteId. Omit to include all sites + all-sites rules.'],
                    'source' => ['type' => Type::string(), 'description' => 'Filter by origin: manual / auto-slug / csv-import / manual-element.'],
                    'type' => ['type' => Type::string(), 'description' => 'Filter by rule type (exact / glob / regex / custom).'],
                    'enabled' => ['type' => Type::boolean()],
                    'search' => ['type' => Type::string(), 'description' => 'Substring match on sourceUri or targetUri.'],
                    'limit' => ['type' => Type::int(), 'defaultValue' => 100],
                    'offset' => ['type' => Type::int(), 'defaultValue' => 0],
                ],
                'description' => 'Returns Beacon redirects ordered by sortOrder ASC, id ASC.',
                'resolve' => [self::class, 'resolveRedirects'],
            ];

            $queries['beaconRedirect'] = [
                'type' => BeaconRedirectType::getType(),
                'args' => [
                    'id' => ['type' => Type::nonNull(Type::int())],
                ],
                'resolve' => [self::class, 'resolveRedirect'],
            ];
        }

        if (!$checkToken || GqlHelper::canSchema('beaconShortLinks', 'read')) {
            $queries['beaconShortLinks'] = [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(BeaconShortLinkType::getType()))),
                'args' => [
                    'siteId' => ['type' => Type::int(), 'description' => 'Filter to short links live on this site. Omit to include every short link.'],
                    'enabled' => ['type' => Type::boolean()],
                    'search' => ['type' => Type::string(), 'description' => 'Substring match on slug or destination.'],
                    'limit' => ['type' => Type::int(), 'defaultValue' => 100],
                ],
                'description' => 'Returns Beacon short links ordered by clicks DESC.',
                'resolve' => [self::class, 'resolveShortLinks'],
            ];
        }

        if (!$checkToken || GqlHelper::canSchema('beaconRedirect404s', 'read')) {
            $queries['beaconRedirect404s'] = [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(BeaconRedirect404Type::getType()))),
                'args' => [
                    'siteId' => ['type' => Type::nonNull(Type::int())],
                    'handled' => ['type' => Type::boolean(), 'description' => 'Filter by handled flag (default: unhandled only).'],
                    'limit' => ['type' => Type::int(), 'defaultValue' => 100],
                ],
                'description' => 'Returns 404-log rows for the given site, ordered by hits DESC.',
                'resolve' => [self::class, 'resolve404s'],
            ];
        }

        // Force-register types so they appear in the schema even when no
        // query references them — keeps `__type(name: "BeaconRedirect")`
        // introspection working.
        GqlEntityRegistry::getOrCreate(BeaconRedirectType::getName(), fn() => BeaconRedirectType::getType());
        GqlEntityRegistry::getOrCreate(BeaconRedirect404Type::getName(), fn() => BeaconRedirect404Type::getType());
        GqlEntityRegistry::getOrCreate(BeaconShortLinkType::getName(), fn() => BeaconShortLinkType::getType());

        return $queries;
    }

    /**
     * @param mixed $source
     * @param array{siteId?: int, enabled?: bool, search?: string, limit?: int} $args
     * @return list<BeaconShortLinkGqlRow>
     */
    public static function resolveShortLinks(mixed $source, array $args): array
    {
        // Short links are localized elements: the shared data row joins the
        // element (for enabled/trashed state) and, when a site is requested, the
        // per-site elements_sites row that proves the slug is live there.
        $query = (new Query())
            ->select([
                'sl.id',
                'sl.propagationMethod',
                'sl.slug',
                'sl.destination',
                'sl.statusCode',
                'sl.clicks',
                'sl.lastClicked',
                'sl.expiresAt',
                'sl.note',
                'sl.dateCreated',
                'sl.dateUpdated',
                'enabled' => 'e.enabled',
            ])
            ->from(['sl' => '{{%beacon_short_links}}'])
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[sl.id]]')
            ->where(['e.dateDeleted' => null]);

        if (isset($args['siteId'])) {
            $query->innerJoin(
                ['es' => '{{%elements_sites}}'],
                '[[es.elementId]] = [[sl.id]] AND [[es.siteId]] = :siteId',
                [':siteId' => (int) $args['siteId']],
            );
        }
        if (isset($args['enabled'])) {
            $query->andWhere(['e.enabled' => (bool) $args['enabled']]);
        }
        if (isset($args['search']) && $args['search'] !== '') {
            $like = '%' . addcslashes((string) $args['search'], '\\%_') . '%';
            $query->andWhere(['or', ['like', 'sl.slug', $like, false], ['like', 'sl.destination', $like, false]]);
        }

        /** @var list<BeaconShortLinkGqlRow> */
        return $query
            ->orderBy(['sl.clicks' => SORT_DESC, 'sl.slug' => SORT_ASC])
            ->limit((int) ($args['limit'] ?? 100))
            ->all();
    }

    /**
     * @param mixed $source
     * @param array{
     *     siteId?: int,
     *     source?: string,
     *     type?: string,
     *     enabled?: bool,
     *     search?: string,
     *     limit?: int,
     *     offset?: int,
     * } $args
     * @return list<BeaconRedirectGqlRow>
     */
    public static function resolveRedirects(mixed $source, array $args): array
    {
        $query = self::redirectBaseQuery();

        if (isset($args['siteId'])) {
            $query->innerJoin(
                ['es' => '{{%elements_sites}}'],
                '[[es.elementId]] = [[r.id]] AND [[es.siteId]] = :siteId',
                [':siteId' => (int) $args['siteId']],
            );
        }
        if (isset($args['source']) && $args['source'] !== '') {
            $query->andWhere(['r.source' => (string) $args['source']]);
        }
        if (isset($args['type']) && $args['type'] !== '' && RedirectType::tryFrom((string) $args['type']) !== null) {
            $query->andWhere(['r.type' => (string) $args['type']]);
        }
        if (isset($args['enabled'])) {
            $query->andWhere(['e.enabled' => (bool) $args['enabled']]);
        }
        if (isset($args['search']) && $args['search'] !== '') {
            $like = '%' . addcslashes((string) $args['search'], '\\%_') . '%';
            $query->andWhere(['or', ['like', 'r.sourceUri', $like, false], ['like', 'r.targetUri', $like, false]]);
        }

        /** @var list<BeaconRedirectGqlRow> */
        return $query
            ->orderBy(['r.sortOrder' => SORT_ASC, 'r.id' => SORT_ASC])
            ->limit((int) ($args['limit'] ?? 100))
            ->offset((int) ($args['offset'] ?? 0))
            ->all();
    }

    /**
     * Base redirect query joined to the element (for enabled state + dates),
     * exposing `enabled` so the GraphQL type keeps its `enabled` field.
     *
     * @return Query<int, BeaconRedirectGqlRow>
     */
    private static function redirectBaseQuery(): Query
    {
        return (new Query())
            ->select([
                'r.id', 'r.propagationMethod', 'r.sourceUri', 'r.targetUri', 'r.statusCode',
                'r.type', 'r.queryStringMode', 'r.hits', 'r.lastHit', 'r.source', 'r.sortOrder',
                'r.elementId', 'r.note', 'r.dateCreated', 'r.dateUpdated',
                'enabled' => 'e.enabled',
            ])
            ->from(['r' => '{{%beacon_redirects}}'])
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[r.id]]')
            ->where(['e.dateDeleted' => null]);
    }

    /**
     * @param mixed $source
     * @param array{id: int} $args
     * @return BeaconRedirectGqlRow|null
     */
    public static function resolveRedirect(mixed $source, array $args): ?array
    {
        /** @var BeaconRedirectGqlRow|null */
        return self::redirectBaseQuery()
            ->andWhere(['r.id' => (int) $args['id']])
            ->one();
    }

    /**
     * @param mixed $source
     * @param array{siteId: int, handled?: bool, limit?: int} $args
     * @return list<BeaconRedirect404GqlRow>
     */
    public static function resolve404s(mixed $source, array $args): array
    {
        $siteId = (int) $args['siteId'];
        $limit = (int) ($args['limit'] ?? 100);

        if (!array_key_exists('handled', $args)) {
            // Default: only the unhandled queue (the headless dashboard's
            // primary use case). Pulled via the same service the CP uses
            // so ordering and filtering stay consistent. The service trims
            // its select to what the CP needs; the GraphQL type requires
            // `siteId` and `handled` as non-null, so we re-add them here.
            /** @var list<Redirect404LogRow> $rows */
            $rows = Redirect404LogQuery::topUnhandled($siteId, $limit);
            return array_map(
                static fn(array $r): array => $r + ['siteId' => $siteId, 'handled' => false],
                $rows,
            );
        }

        /** @var list<BeaconRedirect404GqlRow> */
        return (new Query())
            ->select(['id', 'siteId', 'uri', 'hits', 'firstSeen', 'lastSeen', 'referer', 'handled'])
            ->from('{{%beacon_redirect_404_log}}')
            ->where(['siteId' => $siteId, 'handled' => (bool) $args['handled']])
            ->orderBy(['hits' => SORT_DESC, 'lastSeen' => SORT_DESC])
            ->limit($limit)
            ->all();
    }
}
