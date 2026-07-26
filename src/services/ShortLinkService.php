<?php

namespace anvildev\beacon\services;

use anvildev\beacon\helpers\Db;
use anvildev\beacon\helpers\ShortLinkSlug;
use anvildev\beacon\models\ShortLink;
use anvildev\beacon\records\ShortLinkRecord;
use Craft;
use craft\db\Query;
use yii\base\Component;
use yii\caching\TagDependency;
use yii\db\Expression;

class ShortLinkService extends Component
{
    /**
     * Whether any short link exists at all, on any site.
     *
     * Every 404 that reaches Beacon's response handler would otherwise pay for
     * a rate-limiter round-trip plus a slug lookup, purely to discover that the
     * site does not use short links. Most installs never create one, so this
     * gate turns that whole branch into a single cached boolean.
     *
     * Deliberately conservative and deliberately not per-site: it reports true
     * whenever a data row exists, including for links that are disabled,
     * expired or soft-deleted. Over-reporting only costs the lookup we would
     * have done anyway; under-reporting would silently break resolution. The
     * cache is tagged, and {@see \anvildev\beacon\records\ShortLinkRecord}
     * invalidates it on every write — including the first one, which is the
     * transition that has to be picked up immediately.
     */
    public function anyExist(): bool
    {
        // Stored as 0/1, not as a bool: Yii's cache reports a miss by returning
        // `false`, so `getOrSet()` can never cache a `false` payload — it would
        // re-query on every 404 for precisely the sites this gate exists to
        // spare.
        $flag = Craft::$app->getCache()->getOrSet(
            'beacon.shortLinks.any',
            static fn(): int => (new Query())
                ->from(['sl' => '{{%beacon_short_links}}'])
                ->exists() ? 1 : 0,
            null,
            new TagDependency(['tags' => [ShortLinkRecord::CACHE_TAG]]),
        );

        return (int) $flag === 1;
    }

    /**
     * Resolves a slug to a live short link for the given site. Returns null when
     * no element matches, it isn't enabled (globally or on this site), it's
     * trashed, or it's past its `expiresAt`.
     *
     * Short links are localized, propagating elements: one shared data row
     * ({@see \anvildev\beacon\records\ShortLinkRecord}) joined to the element's
     * per-site `elements_sites` row tells us whether the slug is live here. The
     * slug is globally unique, so this is a single indexed point lookup.
     */
    public function findBySlug(int $siteId, string $slug): ?ShortLink
    {
        /** @var array{id: int|string, slug: string, destination: string, statusCode: int|string}|null $row */
        $row = (new Query())
            ->select([
                'sl.id',
                'sl.slug',
                'sl.destination',
                'sl.statusCode',
            ])
            ->from(['sl' => '{{%beacon_short_links}}'])
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[sl.id]]')
            ->innerJoin(
                ['es' => '{{%elements_sites}}'],
                '[[es.elementId]] = [[sl.id]] AND [[es.siteId]] = :siteId',
                [':siteId' => $siteId],
            )
            ->where(['sl.slug' => $slug])
            ->andWhere(['e.enabled' => true, 'es.enabled' => true])
            ->andWhere(['e.dateDeleted' => null])
            ->andWhere(['or', ['sl.expiresAt' => null], ['>', 'sl.expiresAt', new Expression('NOW()')]])
            ->one();

        if ($row === null) {
            return null;
        }

        return new ShortLink(
            id: (int) $row['id'],
            siteId: $siteId,
            slug: (string) $row['slug'],
            destination: (string) $row['destination'],
            statusCode: (int) $row['statusCode'],
        );
    }

    /**
     * Increments the click counter and stamps `lastClicked`. Errors are
     * logged but never block the redirect (the user already has the
     * destination in flight). Writes the shared data row directly so the hot
     * path never triggers a full element save / re-propagation.
     */
    public function recordClick(int $shortLinkId): void
    {
        try {
            Craft::$app->getDb()->createCommand()
                ->update('{{%beacon_short_links}}', [
                    'clicks' => new Expression('clicks + 1'),
                    'lastClicked' => Db::now(),
                ], ['id' => $shortLinkId])
                ->execute();
        } catch (\yii\db\Exception $e) {
            Craft::warning('Beacon: short-link click update failed: ' . $e->getMessage(), 'beacon');
        }
    }

    /**
     * Validates a short-link slug. Returns null when safe, otherwise an
     * error string for the caller to surface. Mirrors the RedirectImporter
     * URL-allowlist contract: only ASCII-friendly slug characters allowed, no
     * leading slash (we add the `/` at lookup time), no reserved Beacon / Craft
     * prefixes that would collide with element routing.
     */
    public static function validateSlug(string $slug): ?string
    {
        return ShortLinkSlug::validate($slug);
    }
}
