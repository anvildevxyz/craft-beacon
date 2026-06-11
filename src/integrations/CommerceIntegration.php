<?php

namespace anvildev\beacon\integrations;

use anvildev\beacon\events\RegisterSitemapUrlsEvent;
use anvildev\beacon\helpers\SeoFieldReader;
use anvildev\beacon\models\SitemapSettings;
use anvildev\beacon\Plugin;
use craft\base\ElementInterface;
use craft\commerce\elements\Product;
use craft\elements\Entry;
use yii\base\Event;

/**
 * Registers Commerce product URLs for the sitemap whenever the per-site
 * Sitemap settings include the synthetic `__products__` row. Inclusion,
 * priority and changefreq are read from the same `SitemapSettings`
 * structure as Entry sections (no Commerce-specific global toggles).
 */
final class CommerceIntegration
{
    public const SCOPE = '__products__';

    public static function isInstalled(): bool
    {
        return class_exists(Product::class);
    }

    public static function register(): void
    {
        if (!self::isInstalled()) {
            return;
        }

        Event::on(
            Plugin::class,
            Plugin::EVENT_REGISTER_SITEMAP_URLS,
            static function(RegisterSitemapUrlsEvent $event): void {
                $siteId = $event->site->id;
                $sitemap = Plugin::$plugin->siteSettings->getSitemap($siteId);
                if (!self::isIncluded($sitemap)) {
                    return;
                }

                // Hoist changefreq/priority once — loop body would otherwise re-index per product.
                ['changefreq' => $changefreq, 'priority' => $priority] = $sitemap->resolveForSection(self::SCOPE);

                foreach (
                    Product::find()
                        ->siteId($siteId)
                        ->status(Product::STATUS_LIVE)
                        ->limit(null)
                        ->each(500) as $product
                ) {
                    // Gate on the shared noindex check (same as Entry sections via
                    // SitemapController::collectEntries) so a noindex product is excluded.
                    $url = SeoFieldReader::indexableUrl($product);
                    if ($url === null || $url === '') {
                        continue;
                    }
                    $event->pushUrl($url, $product->dateUpdated?->format('c'), $changefreq, $priority);
                }
            },
        );
    }

    public static function isIncluded(SitemapSettings $sitemap): bool
    {
        return in_array(self::SCOPE, $sitemap->sections, true)
            && !in_array(self::SCOPE, $sitemap->excludeSections, true);
    }

    /**
     * True when Commerce is installed, `geoMarkdownEnabled` is on, and the
     * `geoMarkdownSectionAllowlist` is empty or includes `__products__`.
     */
    public static function isMarkdownEligible(): bool
    {
        if (!self::isInstalled()) {
            return false;
        }
        $settings = Plugin::$plugin->settings->get();
        if (!$settings->geoMarkdownEnabled) {
            return false;
        }
        $allowlist = $settings->geoMarkdownSectionAllowlist;
        return $allowlist === [] || in_array(self::SCOPE, $allowlist, true);
    }

    /**
     * Finds the first live Entry — then, when Commerce Product markdown export
     * is eligible, the first live Product — matching `$by` ('id' or 'uri') on
     * the given site. Single source of truth for the GEO Markdown element
     * lookup shared by the export controller, console command and queue job.
     */
    public static function findLiveMarkdownElement(string $by, int|string $value, int $siteId): ?ElementInterface
    {
        if (!in_array($by, ['id', 'uri'], true)) {
            return null;
        }
        $entry = Entry::find()->$by($value)->siteId($siteId)->status(Entry::STATUS_LIVE)->one();
        if ($entry !== null) {
            return $entry;
        }
        if (!self::isMarkdownEligible()) {
            return null;
        }
        /** @phpstan-ignore-next-line — Commerce is an optional dependency */
        return Product::find()->$by($value)->siteId($siteId)->status(Product::STATUS_LIVE)->one();
    }
}
