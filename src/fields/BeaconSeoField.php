<?php

namespace anvildev\beacon\fields;

use anvildev\beacon\helpers\EntitySchema;
use anvildev\beacon\helpers\GeoScoreScope;
use anvildev\beacon\helpers\RobotsDirectives;
use anvildev\beacon\helpers\SeoFieldReader;
use anvildev\beacon\models\AiMarkdownOverride;
use anvildev\beacon\Plugin;
use anvildev\beacon\schemas\SchemaPropertyRegistry;
use anvildev\beacon\web\assets\ai\BeaconAiAsset;
use anvildev\beacon\web\assets\entities\BeaconEntitiesAsset;
use anvildev\beacon\web\assets\seofield\BeaconSeoFieldAsset;
use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\elements\Entry;
use craft\helpers\Json;
use craft\helpers\UrlHelper;

class BeaconSeoField extends Field implements SeoFieldInterface
{
    public static function displayName(): string
    {
        return Craft::t('beacon', 'fields.seo.seo');
    }

    public function getContentColumnType(): string
    {
        return 'text';
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if (is_string($value)) {
            $value = Json::decodeIfJson($value);
        }
        $value = array_merge($this->defaults(), is_array($value) ? $value : []);
        $value['ogImageId'] = self::normalizeAssetId($value['ogImageId'] ?? null);
        $value['entities'] = EntitySchema::sanitize($value['entities'] ?? []);

        foreach ($value['schemaAddons'] ?? [] as $i => $addon) {
            if (isset($addon['mapping']) && is_string($addon['mapping'])) {
                $decoded = Json::decodeIfJson($addon['mapping']);
                $value['schemaAddons'][$i]['mapping'] = is_array($decoded) ? $decoded : [];
            }
        }

        return $value;
    }

    private static function normalizeAssetId(mixed $raw): ?int
    {
        $raw = is_array($raw) ? ($raw[0] ?? null) : $raw;
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        return Json::encode($value);
    }

    public function getInputHtml(mixed $value, ?ElementInterface $element = null): string
    {
        $preview = '';
        $debug = [];
        $fallback = ['title' => '', 'description' => '', 'ogImage' => '', 'sourceMap' => []];
        $sectionDefaults = [];
        $settings = null;
        $liteMode = Plugin::$plugin->settings->get()->seoFieldLiteMode;

        if ($element instanceof Entry && !$element->getIsRevision()) {
            try {
                $fieldValue = is_array($value) ? $value : [];
                $siteName = $element->getSite()->name;
                $typeHandle = $element->getType()->handle ?? null;
                $bundles = is_string($typeHandle) && $typeHandle !== ''
                    ? array_column(Plugin::$plugin->bundles->getSchemasForEntryType($typeHandle), 'schemaType')
                    : [];

                $meta = Plugin::$plugin->metaResolver->resolve(
                    $fieldValue,
                    (string) $element->title,
                    $siteName,
                    Plugin::$plugin->settings->getGeoDefaults(),
                    $element->getUrl(),
                    $element,
                    $bundles,
                );

                $preview = Craft::$app->getView()->renderTemplate('beacon/_seo-field/preview', [
                    'liteMode' => $liteMode,
                    'preview' => [
                        'title' => $meta->title,
                        'description' => $meta->description,
                        'canonical' => $meta->canonical,
                        'robots' => $meta->robots,
                        'twitterCard' => (string) ($meta->twitter['card'] ?? ''),
                        'ogImage' => (string) ($meta->openGraph['image'] ?? ''),
                        'siteName' => $siteName,
                    ],
                ]);
                $debug = [
                    'route' => $element->getUrl(),
                    'sourceMap' => $meta->sourceMap,
                    'schemaBundleCount' => count($bundles),
                    'schemaAddonCount' => count($fieldValue['schemaAddons'] ?? []),
                    'robots' => $meta->robots,
                    'ogType' => (string) ($meta->openGraph['type'] ?? ''),
                    'twitterCard' => (string) ($meta->twitter['card'] ?? ''),
                    'canonical' => (string) ($meta->canonical ?? ''),
                    'hasOgImage' => !empty($meta->openGraph['image']),
                    'titleLength' => mb_strlen((string) ($meta->title ?? '')),
                    'descriptionLength' => mb_strlen((string) ($meta->description ?? '')),
                    'lastUpdated' => $element->dateUpdated?->format(DATE_ATOM),
                    'cache' => 'cp-preview (no frontend cache)',
                ];

                $settings = Plugin::$plugin->settings->get();
                $sectionHandle = $element->getSection()?->handle ?? null;
                if (is_string($sectionHandle) && $sectionHandle !== '') {
                    $allSectionDefaults = $settings->sectionSeoDefaults;
                    $sectionDefaults = is_array($allSectionDefaults[$sectionHandle] ?? null)
                        ? $allSectionDefaults[$sectionHandle]
                        : [];
                }

                $fallback = [
                    'title' => $meta->title,
                    'description' => $meta->description,
                    'ogImage' => (string) ($meta->openGraph['image'] ?? ''),
                    'sourceMap' => $meta->sourceMap,
                ];
            } catch (\Throwable $e) {
                // The preview is a best-effort convenience; any failure (Twig,
                // DB, transform resolution, …) must degrade to an empty preview
                // rather than break the whole CP entry-edit screen.
                Craft::warning('Beacon SEO field preview render failed: ' . $e->getMessage(), 'beacon');
                $debug = ['error' => $e->getMessage()];
            }
        }

        Craft::$app->getView()->registerAssetBundle(BeaconSeoFieldAsset::class);
        Craft::$app->getView()->registerAssetBundle(BeaconEntitiesAsset::class);

        // AI "Generate" affordances load only when a provider is configured, so
        // an unconfigured install ships no AI script and makes zero requests.
        if (Plugin::$plugin->aiClient->isConfigured()) {
            Craft::$app->getView()->registerAssetBundle(BeaconAiAsset::class);
        }

        // Outside the try, settings may not have been loaded — fall back to the
        // plugin singleton here. Reuses the per-request memo regardless.
        $robotsEnabled = RobotsDirectives::resolveEnabledMap(
            ($settings ?? Plugin::$plugin->settings->get())->robotsDirectivesEnabled,
        );
        $robotsDefs = RobotsDirectives::enabledDefinitions($robotsEnabled);

        $entryForSchema = $element instanceof Entry ? $element : null;

        return $this->renderScoreChip($entryForSchema) . Craft::$app->getView()->renderTemplate('beacon/_seo-field/input', [
            'name' => $this->handle,
            'value' => $value,
            'liteMode' => $liteMode,
            'preview' => $preview,
            'sectionDefaults' => $sectionDefaults,
            'debug' => $debug,
            'robotsDirectiveDefs' => $robotsDefs,
            'inheritedSchemas' => $this->collectInheritedSchemas($entryForSchema),
            'schemaSources' => Plugin::$plugin->schemaSources->forEntry($entryForSchema),
            'schemaProperties' => SchemaPropertyRegistry::all(),
            'schemaTypes' => Plugin::$plugin->schema->registeredTypes(),
            'entryId' => $entryForSchema?->id,
            'siteId' => $entryForSchema?->siteId,
            'fallback' => $fallback,
            'resolveUrl' => UrlHelper::actionUrl('beacon/seo-field/resolve-fallback'),
            'entitySearchUrl' => UrlHelper::actionUrl('beacon/entities/search'),
        ]);
    }

    /**
     * Renders the GEO score chip rail prepended to the SEO field input.
     * Returns an empty string when scoring is disabled, the entry is out
     * of scope, or no score row exists yet (first-save case).
     */
    private function renderScoreChip(?Entry $entry): string
    {
        $settings = Plugin::$plugin->settings->get();
        if (!GeoScoreScope::entryEligibleForChip($entry, $settings->geoScoreEnabled, $settings->geoScoreSectionAllowlist)) {
            return '';
        }

        // Draft edits use a provisional id; scores are keyed on the canonical row.
        $canonicalId = (int) $entry->getCanonicalId();
        $siteId = (int) $entry->siteId;

        // Inline compute with persist:false — field render runs inside the draft transaction.
        $score = Plugin::$plugin->geoScore->forElement($canonicalId, $siteId);
        if ($score === null && !$entry->getIsDraft() && !$entry->getIsRevision()) {
            try {
                $score = Plugin::$plugin->geoScore->compute($entry, $siteId, persist: false);
            } catch (\Throwable $e) {
                Craft::warning('Beacon GEO score inline compute failed: ' . $e->getMessage(), 'beacon');
            }
        }

        return Craft::$app->getView()->renderTemplate('beacon/_seo-field/_score-chip', [
            'score' => $score,
            'weakestLabel' => $score?->weakestPillar()?->label(),
            'elementId' => $canonicalId,
            'siteId' => $siteId,
        ]);
    }

    /**
     * Builds the "this entry already emits" list shown above the
     * Additional Schemas section. Pulls from:
     *  - entry-type schema bundles (BundleRegistry)
     *  - the global Identity (Organization/Person) JSON-LD when configured
     *  - BreadcrumbList (always emitted unless disabled per-site)
     *  - GEO provenance WebPage node when the corresponding setting is on
     *
     * Each row carries an optional `editUrl` so authors can jump to the
     * upstream source rather than re-creating the schema in the field.
     *
     * @return list<array{type:string, source:string, editUrl?:string}>
     */
    private function collectInheritedSchemas(?Entry $entry): array
    {
        $rows = [];
        $settings = Plugin::$plugin->settings->get();

        if ($entry !== null) {
            $typeHandle = $entry->getType()->handle ?? null;
            if (is_string($typeHandle) && $typeHandle !== '') {
                foreach (Plugin::$plugin->bundles->getSchemasForEntryType($typeHandle) as $schema) {
                    $rows[] = [
                        'type' => $schema->schemaType,
                        'source' => Craft::t('beacon', 'fields.seo.entry.type.bundle', ['type' => $typeHandle]),
                        'editUrl' => UrlHelper::cpUrl('beacon/schemas/' . $schema->id),
                    ];
                }
            }
        }

        if (is_string($settings->organizationName) && trim($settings->organizationName) !== '') {
            $rows[] = [
                'type' => $settings->identityType ?: 'Organization',
                'source' => Craft::t('beacon', 'fields.seo.site.identity'),
                'editUrl' => UrlHelper::cpUrl('beacon/settings/organization'),
            ];
        }

        $rows[] = [
            'type' => 'BreadcrumbList',
            'source' => Craft::t('beacon', 'fields.seo.auto.per.site.breadcrumbs'),
        ];

        if ($settings->geoProvenanceSchemaEnabled) {
            $rows[] = [
                'type' => 'WebPage',
                'source' => Craft::t('beacon', 'fields.seo.geo.provenance'),
                'editUrl' => UrlHelper::cpUrl('beacon/settings/geo'),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    private function defaults(): array
    {
        return [
            'title' => null,
            'description' => null,
            'ogImageId' => null,
            'canonical' => null,
            'robots' => RobotsDirectives::defaultFieldValues(),
            'aiUsage' => '',
            'schemaAddons' => [],
            'authorIds' => [],
            'entities' => [],
            'aiMarkdown' => [
                'enabled' => AiMarkdownOverride::ENABLED_INHERIT,
                'customFrontMatter' => '',
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function readValueFor(ElementInterface $element): ?array
    {
        return SeoFieldReader::readValueFor($element);
    }

    public static function isNoIndexFor(ElementInterface $element): bool
    {
        return SeoFieldReader::isNoIndexFor($element);
    }

    public static function readDescriptionFor(ElementInterface $element): ?string
    {
        return SeoFieldReader::readDescriptionFor($element);
    }

    public static function readAiMarkdownFor(ElementInterface $element): AiMarkdownOverride
    {
        return SeoFieldReader::readAiMarkdownFor($element);
    }
}
