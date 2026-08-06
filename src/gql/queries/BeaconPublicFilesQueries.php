<?php

namespace anvildev\beacon\gql\queries;

use anvildev\beacon\gql\types\BeaconPublicFilesType;
use Craft;
use craft\gql\base\Query as BaseQuery;
use craft\helpers\Gql as GqlHelper;
use craft\models\Site;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\Type;

/**
 * Root query for the public-file bodies (issue #37): headless sites fetch
 * robots.txt / sitemap.xml / llms.txt content over GraphQL and re-serve it
 * from their own domain rather than exposing the Craft origin.
 *
 * Gated by the `beaconPublicFiles:read` schema component. The bodies are
 * public by nature (they're served anonymously over HTTP anyway), so the
 * gate exists to keep schemas explicit, not to protect secrets.
 */
class BeaconPublicFilesQueries extends BaseQuery
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !GqlHelper::canSchema('beaconPublicFiles', 'read')) {
            return [];
        }

        return [
            'beaconFiles' => [
                'type' => Type::nonNull(BeaconPublicFilesType::getType()),
                'args' => [
                    'siteId' => [
                        'type' => Type::int(),
                        'description' => 'Site to render for; defaults to the primary site.',
                    ],
                ],
                'description' => 'Beacon\'s public discovery files (robots.txt, sitemap.xml + chunks, llms.txt, llms-full.txt, ads.txt, humans.txt) rendered for one site.',
                'resolve' => [self::class, 'resolveFiles'],
            ],
        ];
    }

    /**
     * @param mixed $source
     * @param array{siteId?: ?int} $args
     * @return array{site: Site}
     * @throws UserError when the site id is unknown
     */
    public static function resolveFiles(mixed $source, array $args): array
    {
        $sites = Craft::$app->getSites();
        $siteId = $args['siteId'] ?? null;
        $site = $siteId === null ? $sites->getPrimarySite() : $sites->getSiteById((int) $siteId);
        if ($site === null) {
            throw new UserError("Unknown siteId: {$siteId}");
        }

        return ['site' => $site];
    }
}
