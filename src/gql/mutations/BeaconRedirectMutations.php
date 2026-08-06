<?php

namespace anvildev\beacon\gql\mutations;

use anvildev\beacon\gql\types\BeaconTrack404PayloadType;
use anvildev\beacon\models\Redirect;
use anvildev\beacon\Plugin;
use Craft;
use craft\gql\base\Mutation as BaseMutation;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\Type;

/**
 * Write-side GraphQL surface for headless 404 handling (issue #39). A
 * headless frontend that renders a 404 calls `beaconTrack404` once and
 * gets the same behaviour Beacon's native 404 listener applies on
 * monolith sites:
 *
 *  - redirect matched → hit counter + lastHit bumped, redirect returned
 *  - no match → URI recorded in the 404 log (when `log404s` is enabled
 *    and the user agent isn't a known bot)
 *
 * Gated by the `beaconRedirect404s:log` schema component. Writes flush
 * immediately rather than waiting for `Response::EVENT_AFTER_SEND`, so
 * the row is committed even on queue/console-adjacent request lifecycles.
 */
class BeaconRedirectMutations extends BaseMutation
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getMutations(bool $checkToken = true): array
    {
        if ($checkToken && !GqlHelper::canSchema('beaconRedirect404s', 'log')) {
            return [];
        }

        return [
            'beaconTrack404' => [
                'type' => Type::nonNull(BeaconTrack404PayloadType::getType()),
                'args' => [
                    'siteId' => ['type' => Type::nonNull(Type::int())],
                    'uri' => [
                        'type' => Type::nonNull(Type::string()),
                        'description' => 'Site-relative URI that 404ed (path + optional query string), e.g. "/old-page?utm=x".',
                    ],
                    'userAgent' => [
                        'type' => Type::string(),
                        'description' => 'Original visitor user agent — pass it through so bot traffic is filtered from the 404 log.',
                    ],
                    'referer' => [
                        'type' => Type::string(),
                        'description' => 'Original Referer header, stored with the 404 log row.',
                    ],
                ],
                'description' => 'Runs Beacon\'s 404 pipeline for a headless request: returns the matched redirect (incrementing its hit counter), or logs the URI as a 404.',
                'resolve' => [self::class, 'track404'],
            ],
        ];
    }

    /**
     * @param mixed $source
     * @param array{siteId: int, uri: string, userAgent?: ?string, referer?: ?string} $args
     * @return array{redirect: ?Redirect, logged: bool}
     * @throws UserError when the site id is unknown or the URI is empty
     */
    public static function track404(mixed $source, array $args): array
    {
        $siteId = (int) $args['siteId'];
        if (Craft::$app->getSites()->getSiteById($siteId) === null) {
            throw new UserError("Unknown siteId: {$siteId}");
        }

        $uri = trim((string) $args['uri']);
        if ($uri === '') {
            throw new UserError('uri must not be empty');
        }
        if (!str_starts_with($uri, '/')) {
            $uri = '/' . $uri;
        }

        $plugin = Plugin::$plugin;
        $redirect = $plugin->redirects->findRedirect($siteId, $uri);
        if ($redirect !== null) {
            $plugin->redirects->recordHit($redirect->id);
            return ['redirect' => $redirect, 'logged' => false];
        }

        $logged = false;
        if ($plugin->settings->get()->log404s) {
            $referer = (string) ($args['referer'] ?? '');
            $logged = $plugin->redirect404Log->record(
                $siteId,
                $uri,
                (string) ($args['userAgent'] ?? ''),
                $referer !== '' ? $referer : null,
            );
            if ($logged) {
                $plugin->redirect404Log->flush();
            }
        }

        return ['redirect' => null, 'logged' => $logged];
    }
}
