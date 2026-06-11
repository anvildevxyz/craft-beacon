<?php

namespace anvildev\beacon;

use anvildev\beacon\elements\AuthorElement;
use anvildev\beacon\elements\RedirectElement;
use anvildev\beacon\elements\ShortLinkElement;
use anvildev\beacon\enums\Environment;
use anvildev\beacon\events\RegisterTrackingProvidersEvent;
use anvildev\beacon\gql\queries\BeaconRedirectQueries;
use anvildev\beacon\gql\resolvers\EntryBeaconResolver;
use anvildev\beacon\gql\types\AlternateLinkType;
use anvildev\beacon\gql\types\BeaconRedirect404Type;
use anvildev\beacon\gql\types\BeaconRedirectType;
use anvildev\beacon\gql\types\BeaconShortLinkType;
use anvildev\beacon\gql\types\BreadcrumbItemType;
use anvildev\beacon\gql\types\OpenGraphType;
use anvildev\beacon\gql\types\SchemaArticleType;
use anvildev\beacon\gql\types\SchemaBreadcrumbListType;
use anvildev\beacon\gql\types\SchemaListItemType;
use anvildev\beacon\gql\types\SchemaNodeType;
use anvildev\beacon\gql\types\SchemaProductType;
use anvildev\beacon\gql\types\SeoMetaType;
use anvildev\beacon\gql\types\TwitterCardType;
use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\GeoScoreScope;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\integrations\CommerceIntegration;
use anvildev\beacon\jobs\RecomputeGeoScoreJob;
use anvildev\beacon\schemas\SchemaTemplate;
use anvildev\beacon\services\AiBotsService;
use anvildev\beacon\services\AiCrawlerService;
use anvildev\beacon\services\BotLogService;
use anvildev\beacon\services\BotRegistry;
use anvildev\beacon\services\BreadcrumbService;
use anvildev\beacon\services\BundleRegistry;
use anvildev\beacon\services\EnvironmentMapper;
use anvildev\beacon\services\ExpressionEvaluator;
use anvildev\beacon\services\ExtraSitemapsService;
use anvildev\beacon\services\FeedService;
use anvildev\beacon\services\GeoExportThrottleService;
use anvildev\beacon\services\GeoMarkdownExportService;
use anvildev\beacon\services\GeoMarkdownStore;
use anvildev\beacon\services\GeoScoreService;
use anvildev\beacon\services\HreflangService;
use anvildev\beacon\services\IndexNowService;
use anvildev\beacon\services\LlmsTxtService;
use anvildev\beacon\services\MetaResolverService;
use anvildev\beacon\services\Redirect404LogService;
use anvildev\beacon\services\RedirectImporter;
use anvildev\beacon\services\RedirectMatcher;
use anvildev\beacon\services\RedirectService;
use anvildev\beacon\services\RedirectSuggestionEngine;
use anvildev\beacon\services\RenderCacheService;
use anvildev\beacon\services\RobotsService;
use anvildev\beacon\services\SchemaContextBuilder;
use anvildev\beacon\services\SchemamapService;
use anvildev\beacon\services\SchemaService;
use anvildev\beacon\services\SchemaSourceCatalog;
use anvildev\beacon\services\SchemaSuggestionService;
use anvildev\beacon\services\SettingsService;
use anvildev\beacon\services\ShortLinkService;
use anvildev\beacon\services\SitemapService;
use anvildev\beacon\services\SiteSettingsService;
use anvildev\beacon\services\TrackingProviderRegistry;
use anvildev\beacon\services\TrackingService;
use anvildev\beacon\tracking\providers\CustomScriptProvider;
use anvildev\beacon\tracking\providers\FacebookPixelProvider;
use anvildev\beacon\tracking\providers\GA4Provider;
use anvildev\beacon\tracking\providers\GTMProvider;
use anvildev\beacon\tracking\providers\MatomoProvider;
use anvildev\beacon\twig\GeoMarkdownExtension;
use anvildev\beacon\web\assets\cp\BeaconCpAsset;
use anvildev\beacon\web\GeoMarkdownNegotiator;
use anvildev\beacon\widgets\BotActivityWidget;
use anvildev\beacon\widgets\GeoScoreWidget;
use anvildev\beacon\widgets\IndexNowActivityWidget;
use anvildev\beacon\widgets\MarkdownCoverageWidget;
use anvildev\beacon\widgets\RedirectActivityWidget;
use anvildev\beacon\widgets\SitemapHealthWidget;
use Craft;
use craft\base\Element;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\DefineGqlTypeFieldsEvent;
use craft\events\ModelEvent;
use craft\events\MoveElementEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterCpAlertsEvent;
use craft\events\RegisterElementSortOptionsEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterGqlTypesEvent;
use craft\events\RegisterPreviewTargetsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\SiteEvent;
use craft\events\TemplateEvent;
use craft\gql\TypeManager;
use craft\helpers\Cp;
use craft\helpers\UrlHelper;
use craft\services\Dashboard;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\Gc;
use craft\services\Gql;
use craft\services\Sites;
use craft\services\Structures;
use craft\services\UserPermissions;
use craft\web\Application as WebApplication;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use yii\base\Event;

/**
 * @property-read ExpressionEvaluator $expressions
 * @property-read SchemaService $schema
 * @property-read BundleRegistry $bundles
 * @property-read BreadcrumbService $breadcrumbs
 * @property-read MetaResolverService $metaResolver
 * @property-read RenderCacheService $renderCache
 * @property-read BotRegistry $botRegistry
 * @property-read SitemapService $sitemap
 * @property-read RobotsService $robots
 * @property-read LlmsTxtService $llmsTxt
 * @property-read BotLogService $botLog
 * @property-read RedirectMatcher $redirectMatcher
 * @property-read RedirectService $redirects
 * @property-read RedirectImporter $redirectImporter
 * @property-read Redirect404LogService $redirect404Log
 * @property-read RedirectSuggestionEngine $redirectSuggestions
 * @property-read ShortLinkService $shortLinks
 * @property-read SettingsService $settings
 * @property-read SiteSettingsService $siteSettings
 * @property-read AiCrawlerService $aiCrawlers
 * @property-read AiBotsService $aiBots
 * @property-read HreflangService $hreflang
 * @property-read GeoMarkdownExportService $geoMarkdownExport
 * @property-read GeoMarkdownStore $geoMarkdownStore
 * @property-read GeoExportThrottleService $geoExportThrottle
 * @property-read GeoScoreService $geoScore
 * @property-read TrackingProviderRegistry $trackingRegistry
 * @property-read TrackingService $tracking
 * @property-read FeedService $feeds
 * @property-read ExtraSitemapsService $extraSitemaps
 * @property-read SchemaContextBuilder $schemaContext
 * @property-read SchemaSourceCatalog $schemaSources
 * @property-read SchemaSuggestionService $schemaSuggester
 * @property-read SchemamapService $schemamap
 * @property-read IndexNowService $indexNow
 */
class Plugin extends BasePlugin
{
    /**
     * Register extra sitemap URLs (and override core URLs) while Beacon builds {@see \anvildev\beacon\controllers\SitemapController}.
     *
     * Event class: {@see \anvildev\beacon\events\RegisterSitemapUrlsEvent}
     */
    public const EVENT_REGISTER_SITEMAP_URLS = 'registerSitemapUrls';

    /**
     * Fires before Beacon resolves an entry's meta, letting listeners pre-seed or
     * override the raw inputs that feed {@see \anvildev\beacon\services\MetaResolverService::resolve()}.
     *
     * Event class: {@see \anvildev\beacon\events\DefineMetaEvent}
     *
     * @since 2.1.0
     */
    public const EVENT_DEFINE_META = 'defineBeaconMeta';

    /**
     * Fires from {@see \anvildev\beacon\events\DefineSchemasEvent}. Listeners must
     * be idempotent: this can fire multiple times per request when
     * `beacon.schemas()` is called more than once. See the event class docblock.
     *
     * @since 2.1.0
     */
    public const EVENT_DEFINE_SCHEMAS = 'defineBeaconSchemas';

    /**
     * Fires after Beacon has resolved an entry's meta, letting listeners inspect
     * or mutate the final {@see \anvildev\beacon\models\SeoMeta} before it renders.
     *
     * Event class: {@see \anvildev\beacon\events\AfterResolveMetaEvent}
     *
     * @since 2.1.0
     */
    public const EVENT_AFTER_RESOLVE_META = 'afterResolveBeaconMeta';

    /**
     * Fires while Beacon assembles the raw `<meta>` tag list for `head()`, letting
     * listeners add, replace, or remove individual tags before they're rendered.
     *
     * Event class: {@see \anvildev\beacon\events\DefineMetaTagsEvent}
     *
     * @since 2.2.0
     */
    public const EVENT_DEFINE_META_TAGS = 'defineBeaconMetaTags';

    public static ?Plugin $plugin = null;

    public bool $hasCpSettings = false;
    public bool $hasCpSection = true;
    public string $schemaVersion = '1.0.0';
    public $controllerNamespace = 'anvildev\\beacon\\controllers';

    /**
     * Beacon has no Craft-managed settings model (`hasCpSettings = false`);
     * `config/beacon.php` is read directly by SettingsService. Craft still
     * tries to apply that file as plugin settings on boot and would log a
     * warning per request — accept and ignore it so operator logs stay clean.
     *
     * @param array<string,mixed> $settings
     */
    public function setSettings(array $settings): void
    {
    }

    public function getVersion(): string
    {
        static $version = null;
        $version ??= (function(): string {
            $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
            return is_array($composer) ? (string) ($composer['version'] ?? '0.0.0') : '0.0.0';
        })();
        return $version;
    }

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;
        Craft::setAlias('@anvildev/beacon', $this->getBasePath());

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'anvildev\\beacon\\console\\controllers';
        }

        $this->setComponents([
            'expressions' => ExpressionEvaluator::class,
            'schema' => function(): SchemaService {
                $evaluator = self::$plugin->expressions;
                $types = [
                    'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'ScholarlyArticle',
                    'Product',
                    'Recipe', 'HowTo', 'Course',
                    'FAQPage', 'QAPage', 'ItemList', 'AboutPage', 'ContactPage',
                    'Review',
                    'Person', 'Organization', 'LocalBusiness', 'Restaurant', 'Store',
                    'Event', 'JobPosting',
                    'VideoObject', 'ImageObject', 'PodcastEpisode',
                    'SoftwareApplication',
                ];
                $config = Craft::$app->getConfig()->getConfigFromFile('beacon');
                if (is_array($config)) {
                    if (($config['fullSchemaCatalogue'] ?? false) === true) {
                        $types = array_values(array_unique([
                            ...$types,
                            ...\anvildev\beacon\schemas\GeneratedSchemaCatalogue::types(),
                        ]));
                    }
                    foreach ((array) ($config['schemaTypes'] ?? []) as $extra) {
                        if (is_string($extra) && $extra !== '' && !in_array($extra, $types, true)) {
                            $types[] = $extra;
                        }
                    }
                }
                $templates = [];
                foreach ($types as $type) {
                    $templates[$type] = fn() => new SchemaTemplate($evaluator, $type);
                }
                return new SchemaService($templates);
            },
            'bundles' => BundleRegistry::class,
            'breadcrumbs' => BreadcrumbService::class,
            'metaResolver' => MetaResolverService::class,
            'hreflang' => HreflangService::class,
            'geoMarkdownExport' => GeoMarkdownExportService::class,
            'geoMarkdownStore' => GeoMarkdownStore::class,
            'geoExportThrottle' => GeoExportThrottleService::class,
            'geoScore' => GeoScoreService::class,
            'renderCache' => RenderCacheService::class,
            'botRegistry' => BotRegistry::class,
            'sitemap' => SitemapService::class,
            'robots' => RobotsService::class,
            'llmsTxt' => LlmsTxtService::class,
            'botLog' => fn() => new BotLogService(self::$plugin->botRegistry),
            'redirectMatcher' => RedirectMatcher::class,
            'redirects' => fn() => new RedirectService(self::$plugin->redirectMatcher),
            'redirectImporter' => RedirectImporter::class,
            'redirect404Log' => fn() => new Redirect404LogService(self::$plugin->botRegistry),
            'redirectSuggestions' => RedirectSuggestionEngine::class,
            'shortLinks' => ShortLinkService::class,
            'settings' => SettingsService::class,
            'siteSettings' => SiteSettingsService::class,
            'aiCrawlers' => AiCrawlerService::class,
            'aiBots' => AiBotsService::class,
            'trackingRegistry' => TrackingProviderRegistry::class,
            'tracking' => TrackingService::class,
            'feeds' => FeedService::class,
            'extraSitemaps' => ExtraSitemapsService::class,
            'schemaContext' => SchemaContextBuilder::class,
            'schemaSources' => SchemaSourceCatalog::class,
            'schemaSuggester' => SchemaSuggestionService::class,
            'schemamap' => SchemamapService::class,
            'indexNow' => IndexNowService::class,
        ]);

        Craft::$app->getProjectConfig()
            ->onAdd('beacon.trackingScripts.{uid}', [Plugin::getInstance()->tracking, 'handleChangedScript'])
            ->onUpdate('beacon.trackingScripts.{uid}', [Plugin::getInstance()->tracking, 'handleChangedScript'])
            ->onRemove('beacon.trackingScripts.{uid}', [Plugin::getInstance()->tracking, 'handleDeletedScript']);

        Event::on(
            TrackingProviderRegistry::class,
            TrackingProviderRegistry::EVENT_REGISTER_PROVIDERS,
            static function(RegisterTrackingProvidersEvent $event): void {
                array_push(
                    $event->providers,
                    new GA4Provider(),
                    new GTMProvider(),
                    new FacebookPixelProvider(),
                    new MatomoProvider(),
                    new CustomScriptProvider(),
                );
            }
        );

        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            static function(RegisterComponentTypesEvent $event): void {
                array_push($event->types, AuthorElement::class, ShortLinkElement::class, RedirectElement::class);
            }
        );

        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            static function(\craft\events\RegisterTemplateRootsEvent $event): void {
                $event->roots['beacon'] = __DIR__ . '/templates';
            }
        );

        Event::on(
            self::class,
            self::EVENT_REGISTER_SITEMAP_URLS,
            static function(\anvildev\beacon\events\RegisterSitemapUrlsEvent $event): void {
                if (self::$plugin === null) {
                    return;
                }
                $settings = self::$plugin->settings->get();
                if (!$settings->authorPagesEnabled) {
                    return;
                }
                $authors = AuthorElement::find()
                    ->siteId($event->site->id)
                    ->status(null)
                    ->all();
                foreach ($authors as $author) {
                    $url = $author->getUrl();
                    if (!is_string($url) || $url === '') {
                        continue;
                    }
                    $event->pushUrl(
                        loc: $url,
                        lastmod: $author->dateUpdated?->format(DATE_ATOM),
                        changefreq: 'monthly',
                        priority: 0.4,
                    );
                }
            }
        );

        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            static function(RegisterComponentTypesEvent $event): void {
                array_push(
                    $event->types,
                    \anvildev\beacon\fields\BeaconSeoField::class,
                    \anvildev\beacon\fields\BeaconRedirectSourcesField::class,
                );
            }
        );

        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            static function(RegisterComponentTypesEvent $event): void {
                array_push(
                    $event->types,
                    BotActivityWidget::class,
                    SitemapHealthWidget::class,
                    RedirectActivityWidget::class,
                    MarkdownCoverageWidget::class,
                    IndexNowActivityWidget::class,
                    GeoScoreWidget::class,
                );
            }
        );

        Event::on(
            Entry::class,
            Entry::EVENT_REGISTER_TABLE_ATTRIBUTES,
            static function(RegisterElementTableAttributesEvent $event): void {
                $event->tableAttributes['beacon:geoScore'] = ['label' => Craft::t('beacon', 'GEO score')];
            },
        );
        Event::on(
            Entry::class,
            Entry::EVENT_DEFINE_ATTRIBUTE_HTML,
            static function(DefineAttributeHtmlEvent $event): void {
                if ($event->attribute !== 'beacon:geoScore') {
                    return;
                }
                /** @var Entry $entry */
                $entry = $event->sender;
                $score = Plugin::$plugin->geoScore->forElement((int) $entry->id, (int) $entry->siteId);
                if ($score === null) {
                    $event->html = '<span class="light">—</span>';
                    return;
                }
                $url = UrlHelper::cpUrl('beacon/geo-score/drill-down', [
                    'elementId' => $entry->id,
                    'siteId' => $entry->siteId,
                ]);
                $event->html = sprintf(
                    '<a href="%s" title="%s"><strong>%d</strong>/100</a>',
                    htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars(Craft::t('beacon', 'Open GEO score drill-down'), ENT_QUOTES, 'UTF-8'),
                    $score->score,
                );
            },
        );
        Event::on(
            Entry::class,
            Entry::EVENT_REGISTER_SORT_OPTIONS,
            static function(RegisterElementSortOptionsEvent $event): void {
                $event->sortOptions[] = [
                    'label' => Craft::t('beacon', 'GEO score'),
                    'orderBy' => static function(int $dir) {
                        $direction = $dir === SORT_DESC ? 'DESC' : 'ASC';
                        $expr = '(SELECT [[score]] FROM {{%beacon_geo_score}} [[gs]] WHERE [[gs.elementId]] = [[elements.id]] LIMIT 1)';
                        return new \yii\db\Expression(sprintf('%s %s', $expr, $direction));
                    },
                    'attribute' => 'beacon:geoScore',
                ];
            },
        );

        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_TEMPLATE,
            static function(TemplateEvent $event): void {
                if (!Craft::$app->getRequest()->getIsCpRequest()) {
                    return;
                }
                if (!str_starts_with((string) $event->template, 'beacon/')) {
                    return;
                }
                Craft::$app->getView()->registerAssetBundle(BeaconCpAsset::class);
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event): void {
                $event->rules += [
                    'beacon' => 'beacon/dashboard/index',
                    'beacon/authors' => 'beacon/authors/index',
                    'beacon/authors/new' => 'beacon/authors/edit',
                    'beacon/authors/<authorId:\d+>' => 'beacon/authors/edit',
                    'beacon/redirects' => 'beacon/redirects/index',
                    'beacon/redirects/new' => 'beacon/redirects/edit',
                    'beacon/redirects/import' => 'beacon/redirects/import-form',
                    'beacon/redirects/export' => 'beacon/redirects/export',
                    'beacon/redirects/404s' => 'beacon/redirect-404s/index',
                    'beacon/redirects/404s/bulk' => 'beacon/redirect-404s/bulk',
                    'beacon/redirects/<redirectId:\d+>' => 'beacon/redirects/edit',
                    'beacon/short-links' => 'beacon/short-links/index',
                    'beacon/short-links/new' => 'beacon/short-links/edit',
                    'beacon/short-links/<shortLinkId:\d+>' => 'beacon/short-links/edit',
                    'beacon/schemas' => 'beacon/schemas/index',
                    'beacon/schemas/new' => 'beacon/schemas/edit',
                    'beacon/schemas/<schemaId:\d+>' => 'beacon/schemas/edit',
                    'beacon/sitemap' => 'beacon/sitemap-settings/index',
                    'beacon/crawlers' => 'beacon/ai-crawlers/index',
                    'beacon/crawlers/ai-crawlers' => 'beacon/ai-crawlers/index',
                    'beacon/crawlers/ai-crawlers/rules/new' => 'beacon/ai-crawlers/edit-rule',
                    'beacon/crawlers/ai-crawlers/rules/<ruleId:\d+>' => 'beacon/ai-crawlers/edit-rule',
                    'beacon/crawlers/ai-crawlers/bots/new' => 'beacon/ai-crawlers/edit-bot',
                    'beacon/crawlers/ai-crawlers/bots/<botId:\d+>' => 'beacon/ai-crawlers/edit-bot',
                    'beacon/crawlers/llms-txt' => 'beacon/llms-settings/index',
                    'beacon/crawlers/robots' => 'beacon/robots-settings/index',
                    'beacon/crawlers/humans-txt' => 'beacon/humans-settings/index',
                    'beacon/crawlers/ads-txt' => 'beacon/ads-settings/index',
                    'beacon/tracking' => 'beacon/tracking/index',
                    'beacon/tracking/new' => 'beacon/tracking/edit',
                    'beacon/tracking/<uid:[\w-]+>' => 'beacon/tracking/edit',
                    'beacon/settings' => 'beacon/settings/index',
                    'beacon/settings/<tab:\w+>' => 'beacon/settings/section',
                    'beacon/geo-score/drill-down' => 'beacon/geo-score/drill-down',
                ];
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event): void {
                $event->rules += [
                    'sitemap-<part:\d+>.xml' => 'beacon/sitemap/part',
                    'sitemap.xml' => 'beacon/sitemap/index',
                    'sitemap-news.xml' => 'beacon/extra-sitemaps/news',
                    'sitemap-images.xml' => 'beacon/extra-sitemaps/images',
                    'sitemap-videos.xml' => 'beacon/extra-sitemaps/videos',
                    'robots.txt' => 'beacon/robots/index',
                    'llms.txt' => 'beacon/llms-txt/index',
                    'llms-full.txt' => 'beacon/llms-txt/full',
                    '.well-known/llms.txt' => 'beacon/llms-txt/index',
                    '.well-known/llms-full.txt' => 'beacon/llms-txt/full',
                    'humans.txt' => 'beacon/humans-txt/index',
                    'ads.txt' => 'beacon/ads-txt/index',
                    'geo/export' => 'beacon/geo-export/index',
                    'beacon/schemamap.json' => 'beacon/schemamap/index',
                    '<key:[a-zA-Z0-9-]{8,128}>.txt' => 'beacon/index-now-key/file',
                    'feed/<section:[\w-]+>.json' => 'beacon/feed/json',
                    'feed/<section:[\w-]+>.atom' => 'beacon/feed/atom',
                ];
                if (self::$plugin->settings->get()->geoMarkdownMdSuffixEnabled) {
                    $event->rules['<uri:.+>.md'] = 'beacon/geo-export/md';
                }
            }
        );

        if (Craft::$app->getPlugins()->isPluginInstalled('commerce')) {
            CommerceIntegration::register();
        }

        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event): void {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('beacon', \anvildev\beacon\variables\BeaconVariable::class);
            }
        );

        if (Craft::$app instanceof WebApplication) {
            GeoMarkdownNegotiator::attach();
        }

        Craft::$app->getView()->registerTwigExtension(new GeoMarkdownExtension());

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_TYPES,
            static function(RegisterGqlTypesEvent $event): void {
                array_push(
                    $event->types,
                    SeoMetaType::class,
                    AlternateLinkType::class,
                    BreadcrumbItemType::class,
                    OpenGraphType::class,
                    TwitterCardType::class,
                    SchemaNodeType::class,
                    SchemaArticleType::class,
                    SchemaProductType::class,
                    SchemaBreadcrumbListType::class,
                    SchemaListItemType::class,
                    BeaconRedirectType::class,
                    BeaconRedirect404Type::class,
                    BeaconShortLinkType::class,
                );
            }
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_QUERIES,
            static function(RegisterGqlQueriesEvent $event): void {
                $event->queries = array_merge($event->queries, BeaconRedirectQueries::getQueries());
            },
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS,
            static function(RegisterGqlSchemaComponentsEvent $event): void {
                $event->queries[Craft::t('beacon', 'Beacon redirects')] = [
                    'beaconRedirects:read' => ['label' => Craft::t('beacon', 'Query Beacon redirects')],
                ];
                $event->queries[Craft::t('beacon', 'Beacon 404 log')] = [
                    'beaconRedirect404s:read' => ['label' => Craft::t('beacon', 'Query Beacon 404 log')],
                ];
                $event->queries[Craft::t('beacon', 'Beacon short links')] = [
                    'beaconShortLinks:read' => ['label' => Craft::t('beacon', 'Query Beacon short links')],
                ];
                $event->queries[Craft::t('beacon', 'Beacon GEO score')] = [
                    'beaconGeoScore:read' => ['label' => Craft::t('beacon', 'Read Beacon GEO content score')],
                ];
            },
        );

        Event::on(
            TypeManager::class,
            TypeManager::EVENT_DEFINE_GQL_TYPE_FIELDS,
            static function(DefineGqlTypeFieldsEvent $event): void {
                if ($event->typeName !== 'EntryInterface') {
                    return;
                }
                $event->fields['beacon'] = [
                    'name' => 'beacon',
                    'type' => SeoMetaType::getType(),
                    'description' => 'SEO meta + JSON-LD via Beacon',
                    'resolve' => fn($source) => $source instanceof Entry
                        ? EntryBeaconResolver::resolve($source)
                        : null,
                ];
            }
        );

        Event::on(
            Entry::class,
            Element::EVENT_AFTER_SAVE,
            function(ModelEvent $event): void {
                $entry = $event->sender;
                if (!$entry instanceof Entry) {
                    return;
                }
                $sectionHandle = $entry->getSection()?->handle;
                if ($sectionHandle === null) {
                    return;
                }
                self::$plugin->renderCache->invalidateForSection($sectionHandle);
                if ((int) $entry->id > 0) {
                    self::$plugin->geoMarkdownStore->clear((int) $entry->siteId, (int) $entry->id);
                }
            }
        );

        Event::on(
            Entry::class,
            Element::EVENT_AFTER_DELETE,
            function(Event $event): void {
                $entry = $event->sender;
                if (!$entry instanceof Entry) {
                    return;
                }
                $sectionHandle = $entry->getSection()?->handle;
                if ($sectionHandle === null) {
                    return;
                }
                self::$plugin->renderCache->invalidateForSection($sectionHandle);
                if ((int) $entry->id > 0) {
                    self::$plugin->geoMarkdownStore->clear(null, (int) $entry->id);
                }
            }
        );

        // Must run before EVENT_AFTER_SAVE to stash the old URI so the post-save
        // handler can create an automatic redirect when it changes.
        Event::on(
            Entry::class,
            Element::EVENT_BEFORE_SAVE,
            function(ModelEvent $event): void {
                $entry = $event->sender;
                if (!$entry instanceof Entry || !$entry->id || $entry->getIsDraft()) {
                    return;
                }
                $existing = Entry::find()->id($entry->id)->siteId($entry->siteId)->status(null)->one();
                if ($existing instanceof Entry && $existing->uri) {
                    self::$plugin->redirects->stashOldUri($entry->id, '/' . $existing->uri, (int) $entry->siteId);
                }
            }
        );

        Event::on(
            Entry::class,
            Element::EVENT_AFTER_SAVE,
            function(ModelEvent $event): void {
                $entry = $event->sender;
                if (!$entry instanceof Entry || !$entry->id) {
                    return;
                }
                $oldUri = self::$plugin->redirects->popOldUri($entry->id, (int) $entry->siteId);
                $newUri = $entry->uri ? '/' . $entry->uri : null;
                if ($oldUri && $newUri && $oldUri !== $newUri) {
                    self::$plugin->redirects->createAutoRedirect(
                        siteId: $entry->siteId,
                        source: $oldUri,
                        target: $newUri,
                    );
                }
            }
        );

        Event::on(
            Entry::class,
            Element::EVENT_AFTER_SAVE,
            static function(ModelEvent $event): void {
                $settings = self::$plugin->settings->get();
                if (!$settings->geoScoreEnabled) {
                    return;
                }
                $entry = $event->sender;
                if (!$entry instanceof Entry || !$entry->id || $entry->getIsDraft() || $entry->getIsRevision()) {
                    return;
                }
                if (!GeoScoreScope::sectionInScope($entry->getSection()?->handle, $settings->geoScoreSectionAllowlist)) {
                    return;
                }
                Craft::$app->getQueue()->push(new RecomputeGeoScoreJob([
                    'siteId' => (int) $entry->siteId,
                    'elementId' => (int) $entry->id,
                ]));
            }
        );

        Event::on(
            Entry::class,
            Element::EVENT_AFTER_SAVE,
            function(ModelEvent $event): void {
                if (!self::$plugin->settings->get()->indexNowEnabled) {
                    return;
                }
                $entry = $event->sender;
                if (!$entry instanceof Entry) {
                    return;
                }
                $url = $entry->getUrl();
                if (is_string($url) && $url !== '' && (int) $entry->siteId > 0) {
                    $dedupeKey = sprintf('beacon-indexnow-dedupe:%d:%s', (int) $entry->siteId, hash('sha256', $url));
                    $cache = Craft::$app->getCache();
                    if ($cache->get($dedupeKey) !== false) {
                        return;
                    }
                    $cache->set($dedupeKey, 1, 60);
                }
                try {
                    self::$plugin->indexNow->queueForEntry($entry);
                } catch (\Throwable $e) {
                    Craft::warning('IndexNow listener: ' . $e->getMessage(), 'beacon');
                }
            }
        );

        Event::on(
            WebApplication::class,
            WebApplication::EVENT_BEFORE_REQUEST,
            static function(): void {
                $request = Craft::$app->getRequest();
                if (!$request instanceof \craft\web\Request) {
                    return;
                }
                if ($request->getIsCpRequest() || $request->getIsActionRequest()) {
                    return;
                }
                self::$plugin->botLog->logIfBot(
                    $request->getUserAgent() ?? '',
                    $request->getUrl(),
                    Craft::$app->getSites()->getCurrentSite()->id,
                );
            }
        );

        // Merge two Gc::EVENT_RUN handlers to avoid separate event-listener overhead.
        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            static function(): void {
                $settings = self::$plugin->settings->get();
                self::$plugin->botLog->gc($settings->botLogRetentionDays);
                self::$plugin->redirect404Log->prune($settings->log404RetentionDays);
            }
        );

        // Redirect sort-order must be re-synced after any structural move (drag-reorder).
        // Both INSERT and UPDATE fire because Craft may emit either when reordering.
        $resyncRedirectOrder = static function(MoveElementEvent $event): void {
            if ($event->element instanceof RedirectElement) {
                self::$plugin->redirects->markSortResyncPending();
            }
        };
        Event::on(Structures::class, Structures::EVENT_AFTER_UPDATE_ELEMENT, $resyncRedirectOrder);
        Event::on(Structures::class, Structures::EVENT_AFTER_INSERT_ELEMENT, $resyncRedirectOrder);

        Craft::$app->on(\yii\base\Application::EVENT_AFTER_REQUEST, static function(): void {
            self::$plugin->redirects->flushSortResync();
        });

        Event::on(
            \craft\web\Response::class,
            \craft\web\Response::EVENT_BEFORE_SEND,
            function(\yii\base\Event $event): void {
                $response = $event->sender;
                if (!$response instanceof \craft\web\Response) {
                    return;
                }
                if ($response->getStatusCode() !== 404) {
                    return;
                }
                $request = Craft::$app->getRequest();
                if (!$request instanceof \craft\web\Request) {
                    return;
                }
                if ($request->getIsCpRequest() || $request->getIsActionRequest()) {
                    return;
                }

                try {
                    $uri = '/' . trim($request->getPathInfo(), '/');
                    $qs = $request->getQueryString();
                    if ($qs !== '') {
                        $uri .= '?' . $qs;
                    }
                    $siteId = Craft::$app->getSites()->getCurrentSite()->id;
                    $redirect = self::$plugin->redirects->findRedirect($siteId, $uri);
                    if ($redirect !== null) {
                        self::$plugin->redirects->bufferHit($redirect->id);
                        $response->setStatusCode($redirect->statusCode);
                        $response->headers->set('Location', $redirect->resolvedTarget);
                        $response->content = '';
                        $response->data = null;
                        $response->stream = null;
                        return;
                    }
                    $slug = ltrim($request->getPathInfo(), '/');
                    if ($slug !== '') {
                        try {
                            self::$plugin->geoExportThrottle->enforce('short_link_resolve', 120);
                        } catch (\yii\web\TooManyRequestsHttpException) {
                            $response->setStatusCode(429);
                            $response->content = '';
                            $response->data = null;
                            $response->stream = null;
                            return;
                        }
                        $shortLink = self::$plugin->shortLinks->findBySlug($siteId, $slug);
                        if ($shortLink !== null) {
                            self::$plugin->shortLinks->recordClick($shortLink->id);
                            $response->setStatusCode($shortLink->statusCode);
                            $response->headers->set('Location', $shortLink->destination);
                            $response->content = '';
                            $response->data = null;
                            $response->stream = null;
                            return;
                        }
                    }
                    if (self::$plugin->settings->get()->log404s) {
                        self::$plugin->redirect404Log->record(
                            $siteId,
                            $uri,
                            (string) ($request->getUserAgent() ?? ''),
                            $request->getReferrer(),
                        );
                    }
                } catch (\Throwable $e) {
                    Craft::warning('Beacon: 404 redirect handler failed: ' . $e->getMessage(), 'beacon');
                }
            }
        );

        Event::on(
            \craft\web\Response::class,
            \craft\web\Response::EVENT_AFTER_SEND,
            static function(): void {
                self::$plugin->redirects->flushHits();
                self::$plugin->redirect404Log->flush();
                self::$plugin->botLog->flush();
            },
        );

        Event::on(
            Cp::class,
            Cp::EVENT_REGISTER_ALERTS,
            static function(RegisterCpAlertsEvent $event): void {
                $env = EnvironmentMapper::resolveActive();
                if ($env === Environment::Production || $env === Environment::Dev) {
                    return;
                }
                $path = trim(Http::request()->getPathInfo(), '/');
                if ($path !== 'beacon') {
                    return;
                }
                $event->alerts[] = Craft::t(
                    'beacon',
                    'Beacon is emitting <code>noindex</code> on every front-end page because the resolved environment is not <code>production</code>. Set <code>CRAFT_ENVIRONMENT=production</code> to publish indexable pages.',
                );
            },
        );

        Event::on(
            Entry::class,
            Element::EVENT_REGISTER_PREVIEW_TARGETS,
            static function(RegisterPreviewTargetsEvent $event): void {
                $entry = $event->sender;
                if (!$entry instanceof Entry) {
                    return;
                }
                $settings = self::$plugin->settings->get();
                if (!$settings->geoMarkdownEnabled) {
                    return;
                }
                $handle = $entry->getSection()?->handle ?? '';
                if ($settings->geoMarkdownSectionAllowlist !== [] && !in_array($handle, $settings->geoMarkdownSectionAllowlist, true)) {
                    return;
                }
                $url = $entry->getUrl();
                if (!is_string($url) || $url === '') {
                    return;
                }
                $event->previewTargets[] = [
                    'label' => Craft::t('beacon', 'GEO Markdown'),
                    'url' => $settings->geoMarkdownMdSuffixEnabled
                        ? rtrim($url, '/') . '.md'
                        : '/geo/export?id=' . (int) $entry->id,
                ];
            }
        );

        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => Craft::t('beacon', 'Beacon'),
                    'permissions' => BeaconPermissions::definitions(),
                ];
            }
        );

        Event::on(
            Sites::class,
            Sites::EVENT_AFTER_SAVE_SITE,
            function(SiteEvent $event): void {
                if ($event->isNew && $event->site->id !== null) {
                    self::$plugin->siteSettings->seedDefaultsForSite($event->site->id);
                }
            }
        );
    }

    /**
     * @return array{label: string, url?: string, subnav: array<string, array{label: string, url: string}>}
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'Beacon';

        $allItems = [
            'dashboard' => ['perm' => BeaconPermissions::VIEW_DASHBOARD, 'label' => 'Dashboard', 'url' => 'beacon'],
            'authors' => ['perm' => BeaconPermissions::EDIT_AUTHORS, 'label' => 'Authors', 'url' => 'beacon/authors'],
            'redirects' => ['perm' => BeaconPermissions::EDIT_REDIRECTS, 'label' => 'Redirects', 'url' => 'beacon/redirects'],
            'shortLinks' => ['perm' => BeaconPermissions::EDIT_SHORT_LINKS, 'label' => Craft::t('beacon', 'Short links'), 'url' => 'beacon/short-links'],
            'schemas' => ['perm' => BeaconPermissions::EDIT_SCHEMAS, 'label' => 'Schemas', 'url' => 'beacon/schemas'],
            'sitemap' => ['perm' => BeaconPermissions::EDIT_SITEMAP, 'label' => 'Sitemap', 'url' => 'beacon/sitemap'],
            'tracking' => ['perm' => BeaconPermissions::EDIT_TRACKING, 'label' => Craft::t('beacon', 'Tracking'), 'url' => 'beacon/tracking'],
            'crawlers' => ['perm' => BeaconPermissions::EDIT_CRAWLERS, 'label' => Craft::t('beacon', 'Crawlers'), 'url' => 'beacon/crawlers'],
            'settings' => ['perm' => BeaconPermissions::EDIT_SETTINGS, 'label' => 'Settings', 'url' => 'beacon/settings'],
        ];

        $subnav = [];
        $isAdmin = Craft::$app->getUser()->getIsAdmin();
        foreach ($allItems as $key => $row) {
            if ($isAdmin || BeaconPermissions::userCan($row['perm'])) {
                $subnav[$key] = ['label' => $row['label'], 'url' => $row['url']];
            }
        }
        $item['subnav'] = $subnav;
        return $item;
    }
}
