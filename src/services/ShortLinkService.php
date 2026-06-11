<?php

namespace anvildev\beacon\services;

use anvildev\beacon\helpers\Db;
use anvildev\beacon\helpers\ShortLinkSlug;
use anvildev\beacon\models\ShortLink;
use Craft;
use craft\db\Query;
use yii\base\Component;
use yii\db\Expression;

class ShortLinkService extends Component
{
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
