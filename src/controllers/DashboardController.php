<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\enums\DashboardActivityType;
use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\Db;
use anvildev\beacon\models\DashboardActivityEvent;
use anvildev\beacon\Plugin;
use anvildev\beacon\records\SchemaRecord;
use anvildev\beacon\records\TrackingScriptRecord;
use Craft;
use craft\web\Controller;
use DateTime;
use yii\web\Response;

/**
 * @phpstan-import-type RedirectDashboardStats from \anvildev\beacon\services\RedirectService
 */
class DashboardController extends Controller
{
    use BeaconCpPermissionTrait;

    protected const BEACON_PERMISSION = BeaconPermissions::VIEW_DASHBOARD;

    private const SQL_DT = 'Y-m-d H:i:s';

    /**
     * Renders the Beacon dashboard with the aggregated card/activity bundle for
     * the current site.
     */
    public function actionIndex(): Response
    {
        return $this->renderTemplate('beacon/dashboard', $this->loadBundle(
            Craft::$app->getSites()->getCurrentSite()->id,
        ));
    }

    /**
     * Aggregates the data the dashboard template needs into a single
     * cache-eligible bundle, keyed per current site so multi-site CPs
     * don't show a stale neighbour's numbers.
     *
     * @return array<string, mixed>
     */
    private function loadBundle(int $currentSiteId): array
    {
        $cache = Craft::$app->getCache();
        $cacheKey = 'beacon.dashboard.v2.' . $currentSiteId;
        if (is_array($cached = $cache->get($cacheKey))) {
            return $cached;
        }

        $bundle = [
            'sitemapCard' => $this->loadSitemapCard($currentSiteId),
            'redirectsCard' => $this->loadRedirectsCard($currentSiteId),
            'trackingCard' => $this->loadTrackingCard(),
            'breadcrumbsCard' => $this->loadBreadcrumbsCard($currentSiteId),
            'botLogStats' => $this->loadBotLogStats(),
            'activity' => $this->loadActivityFeed(10),
            'setupChecklist' => $this->loadSetupChecklist($currentSiteId),
        ];

        $cache->set($cacheKey, $bundle, 60);
        return $bundle;
    }

    /**
     * SitemapService doesn't expose total URL count or a generated-at
     * timestamp cheaply, so we report only what the settings record knows:
     * whether sections are configured.
     *
     * @return array{urlCount: int|null, lastGenerated: DateTime|null, enabled: bool}
     */
    private function loadSitemapCard(int $siteId): array
    {
        return [
            'urlCount' => null,
            'lastGenerated' => null,
            'enabled' => Plugin::$plugin->siteSettings->getSitemap($siteId)->sections !== [],
        ];
    }

    /**
     * @return array{active: int, stale: int, lastHit: DateTime|null}
     */
    private function loadRedirectsCard(int $siteId): array
    {
        /** @var RedirectDashboardStats $stats */
        $stats = Plugin::$plugin->redirects->dashboardStats();
        $lastHit = $stats['lastHit'] !== null
            ? (DateTime::createFromFormat(self::SQL_DT, $stats['lastHit']) ?: null)
            : null;

        return [
            'active' => $stats['active'],
            'stale' => $stats['stale'],
            'lastHit' => $lastHit,
        ];
    }

    /**
     * @return array{total: int}
     */
    private function loadTrackingCard(): array
    {
        return ['total' => count(Plugin::$plugin->tracking->list())];
    }

    /**
     * @return array{enabled: bool, homeLabel: string, enabledSiteCount: int, totalSiteCount: int}
     */
    private function loadBreadcrumbsCard(int $siteId): array
    {
        $siteSettings = Plugin::$plugin->siteSettings;
        $current = $siteSettings->getBreadcrumbs($siteId);
        $sites = Craft::$app->getSites()->getAllSites();

        $enabledCount = count(array_filter(
            $sites,
            fn($site) => $siteSettings->getBreadcrumbs($site->id)->enabled,
        ));

        return [
            'enabled' => $current->enabled,
            'homeLabel' => $current->homeLabel,
            'enabledSiteCount' => $enabledCount,
            'totalSiteCount' => count($sites),
        ];
    }

    /**
     * Merges recent redirect hits and bot crawls into a single time-sorted
     * feed. Each redirect/bot row is over-fetched (20) so the merged result
     * still has plenty of recent entries after the time-DESC sort is sliced
     * to $limit.
     *
     * @return list<DashboardActivityEvent>
     */
    private function loadActivityFeed(int $limit = 10): array
    {
        $events = [];
        $plugin = Plugin::$plugin;

        foreach ($plugin->redirects->recentlyHit(20) as $row) {
            if (!is_string($when = $row->lastHit) || $when === ''
                || ($whenDt = DateTime::createFromFormat(self::SQL_DT, $when)) === false) {
                continue;
            }
            $events[] = new DashboardActivityEvent(
                DashboardActivityType::Redirect,
                $whenDt,
                [
                    'id' => (int) $row->id,
                    'sourceUri' => (string) $row->sourceUri,
                    'targetUri' => (string) $row->targetUri,
                    'statusCode' => (int) $row->statusCode,
                ],
            );
        }

        foreach ($plugin->botLog->recentActivity(20, 7) as $r) {
            if (($whenDt = DateTime::createFromFormat(self::SQL_DT, $r['hitAt'])) === false) {
                continue;
            }
            $events[] = new DashboardActivityEvent(
                DashboardActivityType::Bot,
                $whenDt,
                ['botName' => $r['botName'], 'path' => $r['path']],
            );
        }

        $events = [
            ...$events,
            ...$this->collectListEvents(DashboardActivityType::Schema, $plugin->schema->list(), static fn(SchemaRecord $r) => [
                'id' => (int) $r->id,
                'entryTypeHandle' => (string) $r->entryTypeHandle,
                'schemaType' => (string) $r->schemaType,
            ]),
            ...$this->loadRecentMaintenanceEvents(),
            ...$this->collectListEvents(DashboardActivityType::Tracking, $plugin->tracking->list(), static fn(TrackingScriptRecord $r) => [
                'uid' => (string) $r->uid,
                'name' => (string) $r->name,
            ]),
        ];

        usort($events, static fn(DashboardActivityEvent $a, DashboardActivityEvent $b) => $b->when->getTimestamp() <=> $a->when->getTimestamp());
        return array_slice($events, 0, $limit);
    }

    /**
     * @template T of SchemaRecord|TrackingScriptRecord
     * @param iterable<T> $rows  Each row must expose a `dateUpdated` property (DateTime|null).
     * @param callable(T): array<string, mixed> $dataFn
     * @return list<DashboardActivityEvent>
     */
    private function collectListEvents(DashboardActivityType $type, iterable $rows, callable $dataFn): array
    {
        $events = [];
        $i = 0;
        foreach ($rows as $row) {
            if ($i++ >= 6) {
                break;
            }
            if (!$row->dateUpdated instanceof DateTime) {
                continue;
            }
            $events[] = new DashboardActivityEvent($type, $row->dateUpdated, $dataFn($row));
        }
        return $events;
    }

    /**
     * @return list<DashboardActivityEvent>
     */
    private function loadRecentMaintenanceEvents(): array
    {
        $events = [];
        $map = [
            ['type' => DashboardActivityType::Sitemap, 'alias' => '@webroot/sitemap.xml'],
            ['type' => DashboardActivityType::Llms, 'alias' => '@webroot/llms.txt'],
        ];
        foreach ($map as $entry) {
            $path = Craft::getAlias($entry['alias']);
            if (!is_string($path) || !is_file($path) || !is_int($mtime = @filemtime($path)) || $mtime <= 0) {
                continue;
            }
            $events[] = new DashboardActivityEvent($entry['type'], (new DateTime())->setTimestamp($mtime), []);
        }
        return $events;
    }

    /**
     * @return array{rows:int, retentionDays:int, approxBytes:int, projectedBytes:int}
     */
    private function loadBotLogStats(): array
    {
        $db = Craft::$app->getDb();
        $rows = (int) $db->createCommand('SELECT COUNT(*) FROM {{%beacon_bot_log}}')->queryScalar();
        $retentionDays = max(1, Plugin::$plugin->settings->get()->botLogRetentionDays);

        $approxBytes = (int) $db->createCommand(
            'SELECT COALESCE(SUM(LENGTH([[botName]]) + LENGTH([[path]]) + 32), 0) FROM {{%beacon_bot_log}}'
        )->queryScalar();

        $recentRows = (int) $db->createCommand(
            'SELECT COUNT(*) FROM {{%beacon_bot_log}} WHERE [[hitAt]] >= :cutoff',
            ['cutoff' => Db::cutoff(7, 'days')],
        )->queryScalar();
        $avgRowsPerDay = max(1, (int) ceil($recentRows / 7));
        $avgBytesPerRow = $rows > 0 ? max(1, (int) ceil($approxBytes / $rows)) : 128;

        return [
            'rows' => $rows,
            'retentionDays' => $retentionDays,
            'approxBytes' => $approxBytes,
            'projectedBytes' => $avgRowsPerDay * $retentionDays * $avgBytesPerRow,
        ];
    }

    /**
     * Quick "is the site set up for SEO + GEO + AI search?" checklist.
     *
     * Each row is independent and links to the relevant settings screen.
     * Items report only "done" / "not done" — deliberately not weighted into
     * a single score, because the right configuration depends on the site.
     *
     * @return list<array{key:string, label:string, done:bool, url:string}>
     */
    private function loadSetupChecklist(int $siteId): array
    {
        $plugin = Plugin::$plugin;
        $settings = $plugin->settings->get();
        $siteSettings = $plugin->siteSettings;
        $sitemap = $siteSettings->getSitemap($siteId);
        $llms = $siteSettings->getLlms($siteId);
        $robots = $siteSettings->getRobots($siteId);

        return [
            ['key' => 'sitemap', 'label' => Craft::t('beacon', 'Sitemap configured (at least one section enabled)'),
                'done' => $sitemap->sections !== [], 'url' => 'beacon/sitemap', ],
            ['key' => 'robots', 'label' => Craft::t('beacon', 'robots.txt user-agent rules defined'),
                'done' => $robots->userAgentRules !== [], 'url' => 'beacon/crawlers/robots', ],
            ['key' => 'llms', 'label' => Craft::t('beacon', 'llms.txt enabled with at least one section'),
                'done' => $llms->enabled && $llms->sections !== [], 'url' => 'beacon/crawlers/llms-txt', ],
            ['key' => 'aiCrawlers', 'label' => Craft::t('beacon', 'AI crawler rules configured'),
                'done' => $plugin->aiCrawlers->getAllRules() !== [], 'url' => 'beacon/crawlers', ],
            ['key' => 'geoMarkdown', 'label' => Craft::t('beacon', 'GEO Markdown enabled'),
                'done' => $settings->geoMarkdownEnabled, 'url' => 'beacon/settings/geo', ],
            ['key' => 'allowlist', 'label' => Craft::t('beacon', 'GEO Markdown section allowlist set'),
                'done' => $settings->geoMarkdownEnabled && $settings->geoMarkdownSectionAllowlist !== [], 'url' => 'beacon/settings/geo', ],
            ['key' => 'organization', 'label' => Craft::t('beacon', 'Organization identity configured'),
                'done' => is_string($settings->organizationName) && trim($settings->organizationName) !== '', 'url' => 'beacon/settings/organization', ],
        ];
    }
}
