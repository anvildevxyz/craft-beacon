<?php

declare(strict_types=1);

namespace anvildev\beacon;

use anvildev\beacon\elements\AuthorElement;
use anvildev\beacon\elements\RedirectElement;
use anvildev\beacon\elements\ShortLinkElement;
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
use Illuminate\Support\ServiceProvider;

/**
 * Beacon Service Provider for Craft 6 (Laravel)
 *
 * This replaces the Yii-based Plugin class with a Laravel ServiceProvider.
 * Handles registration and bootstrapping of all Beacon services.
 */
class BeaconServiceProvider extends ServiceProvider
{
    /**
     * Register Beacon services in the container
     */
    public function register(): void
    {
        $this->registerServices();
        $this->registerElements();
    }

    /**
     * Bootstrap Beacon after the container is fully loaded
     */
    public function boot(): void
    {
        $this->registerListeners();
        $this->registerRoutes();
        $this->registerGraphQL();
        $this->registerPermissions();
        $this->registerDashboard();
    }

    /**
     * Register all Beacon services as singletons
     */
    protected function registerServices(): void
    {
        // Core services
        $this->app->singleton(ExpressionEvaluator::class);
        $this->app->singleton(SchemaService::class);
        $this->app->singleton(BundleRegistry::class);
        $this->app->singleton(BreadcrumbService::class);
        $this->app->singleton(MetaResolverService::class);
        $this->app->singleton(RenderCacheService::class);

        // Bot services
        $this->app->singleton(BotRegistry::class);
        $this->app->singleton(BotLogService::class);
        $this->app->singleton(AiCrawlerService::class);
        $this->app->singleton(AiBotsService::class);

        // Redirect services
        $this->app->singleton(RedirectMatcher::class);
        $this->app->singleton(RedirectService::class);
        $this->app->singleton(RedirectImporter::class);
        $this->app->singleton(Redirect404LogService::class);
        $this->app->singleton(RedirectSuggestionEngine::class);

        // SEO services
        $this->app->singleton(SitemapService::class);
        $this->app->singleton(RobotsService::class);
        $this->app->singleton(LlmsTxtService::class);
        $this->app->singleton(HreflangService::class);

        // Short links
        $this->app->singleton(ShortLinkService::class);

        // Settings
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(SiteSettingsService::class);

        // Geo services
        $this->app->singleton(GeoMarkdownExportService::class);
        $this->app->singleton(GeoMarkdownStore::class);
        $this->app->singleton(GeoExportThrottleService::class);
        $this->app->singleton(GeoScoreService::class);

        // Tracking
        $this->app->singleton(TrackingProviderRegistry::class);
        $this->app->singleton(TrackingService::class);

        // Feeds & extra content
        $this->app->singleton(FeedService::class);
        $this->app->singleton(ExtraSitemapsService::class);

        // Schema services
        $this->app->singleton(SchemaContextBuilder::class);
        $this->app->singleton(SchemaSourceCatalog::class);
        $this->app->singleton(SchemaSuggestionService::class);
        $this->app->singleton(SchemamapService::class);

        // Other services
        $this->app->singleton(EnvironmentMapper::class);
        $this->app->singleton(IndexNowService::class);
    }

    /**
     * Register element types
     */
    protected function registerElements(): void
    {
        // TODO: Register element types with Craft's element registry
        // In Craft 6, this might be done via events or a dedicated registry
    }

    /**
     * Register event listeners
     */
    protected function registerListeners(): void
    {
        // TODO: Convert Yii Event::on() calls to Laravel event listeners
        // Example:
        // Event::listen(SiteSaved::class, [SiteListener::class, 'onSaveSite']);
    }

    /**
     * Register routes
     */
    protected function registerRoutes(): void
    {
        // TODO: Register Beacon routes
        // In Craft 6, this might use Laravel routing or Craft's route system
    }

    /**
     * Register GraphQL types and queries
     */
    protected function registerGraphQL(): void
    {
        // TODO: Register GraphQL types and queries
    }

    /**
     * Register user permissions
     */
    protected function registerPermissions(): void
    {
        // TODO: Register Beacon permissions with Craft
    }

    /**
     * Register dashboard widgets
     */
    protected function registerDashboard(): void
    {
        // TODO: Register Beacon dashboard widgets
    }

    /**
     * Get accessible services as facade accessor methods
     *
     * Usage:
     * $expressions = app(ExpressionEvaluator::class);
     * $schema = app(SchemaService::class);
     */
    public static function bootAccessors(): void
    {
        // To be called after service provider boots
    }
}
