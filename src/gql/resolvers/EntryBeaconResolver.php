<?php

namespace anvildev\beacon\gql\resolvers;

use anvildev\beacon\gql\SchemaNodeMapper;
use anvildev\beacon\helpers\SeoFieldReader;
use anvildev\beacon\Plugin;
use craft\base\Element;
use craft\elements\Entry;

/**
 * @phpstan-import-type BeaconOpenGraphGql from \anvildev\beacon\gql\GqlArrayShapes
 * @phpstan-import-type BeaconSeoMetaGqlSource from \anvildev\beacon\gql\GqlArrayShapes
 * @phpstan-import-type BeaconTwitterGql from \anvildev\beacon\gql\GqlArrayShapes
 * @phpstan-import-type BreadcrumbItem from \anvildev\beacon\types\ArrayShapes
 * @phpstan-import-type JsonLdNode from \anvildev\beacon\gql\GqlArrayShapes
 */
class EntryBeaconResolver
{
    /**
     * @return BeaconSeoMetaGqlSource
     */
    public static function resolve(Entry $entry): array
    {
        $entryTitle = (string) $entry->title;
        $siteName = $entry->getSite()->name;
        $geoDefaults = Plugin::$plugin->settings->getGeoDefaults();

        $fieldValue = self::extractSeoFieldValue($entry);

        $meta = Plugin::$plugin->metaResolver->resolve(
            $fieldValue,
            $entryTitle,
            $siteName,
            $geoDefaults,
            $entry->getUrl(),
            $entry,
            self::bundleSchemaTypes($entry),
        );

        $schemas = self::resolveSchemas($entry, $fieldValue);
        // Encode each schema once; both schemas (string list) and schemaNodes
        // (rawJson field) share the same encoded payload.
        $schemaJsonStrings = array_map([SchemaNodeMapper::class, 'encode'], $schemas);
        $breadcrumbs = self::resolveBreadcrumbs($entry);

        return [
            'title' => $meta->title,
            'description' => $meta->description,
            'canonical' => $meta->canonical,
            'robots' => $meta->robots,
            'alternates' => array_map(
                static fn(array $row): array => ['hreflang' => $row['hreflang'], 'href' => $row['href']],
                $meta->alternates,
            ),
            'articlePublishedTime' => $meta->articleTimes['publishedTime'] ?? null,
            'articleModifiedTime' => $meta->articleTimes['modifiedTime'] ?? null,
            'breadcrumbs' => $breadcrumbs,
            'openGraph' => self::openGraphForGql($meta->openGraph),
            'twitter' => self::twitterForGql($meta->twitter),
            'schemas' => $schemaJsonStrings,
            'schemaNodes' => array_map(
                static fn(array $schema, string $json): array => SchemaNodeMapper::map($schema, $json),
                $schemas,
                $schemaJsonStrings,
            ),
            // Threaded for the lazy GEO score resolver in SeoMetaType — the
            // score table is only touched when the `geoScore` field is in
            // the selection set, so unrelated `beacon` queries stay cheap.
            '__beaconElementId' => (int) ($entry->id ?? 0),
            '__beaconSiteId' => (int) ($entry->siteId ?? 0),
        ];
    }

    /**
     * @return list<string>
     */
    private static function bundleSchemaTypes(Entry $entry): array
    {
        $handle = $entry->getType()->handle;
        if ($handle === null || $handle === '') {
            return [];
        }

        return array_column(
            Plugin::$plugin->bundles->getSchemasForEntryType($handle),
            'schemaType',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function extractSeoFieldValue(Element $entry): array
    {
        return SeoFieldReader::readValueFor($entry) ?? [];
    }

    /**
     * @param array<string,mixed> $fieldValue
     * @return list<JsonLdNode>
     */
    private static function resolveSchemas(Entry $entry, array $fieldValue): array
    {
        $handle = $entry->getType()->handle;
        if ($handle === null) {
            return [];
        }

        $schemas = Plugin::$plugin->bundles->getSchemasForEntryType($handle);
        if ($schemas === []) {
            return [];
        }

        $context = Plugin::$plugin->schemaContext->build($entry, $fieldValue);
        $addons = $fieldValue['schemaAddons'] ?? [];
        $bundle = self::buildAdHocBundle($schemas);
        return Plugin::$plugin->schema->render($bundle, is_array($addons) ? $addons : [], $context);
    }

    /**
     * @param list<\anvildev\beacon\models\Schema> $schemas
     */
    private static function buildAdHocBundle(array $schemas): \anvildev\beacon\models\SchemaBundle
    {
        $bundle = new \anvildev\beacon\models\SchemaBundle();
        $bundle->entryTypeHandle = $schemas[0]->entryTypeHandle;
        $bundle->schemas = array_map(
            fn(\anvildev\beacon\models\Schema $s) => array_filter(
                ['type' => $s->schemaType, 'mapping' => $s->mapping],
                fn($v) => $v !== null,
            ),
            $schemas,
        );
        return $bundle;
    }

    /**
     * @param array<string, mixed> $openGraph
     * @return BeaconOpenGraphGql
     */
    private static function openGraphForGql(array $openGraph): array
    {
        /** @var BeaconOpenGraphGql */
        return [
            'title' => is_string($openGraph['title'] ?? null) ? $openGraph['title'] : null,
            'description' => is_string($openGraph['description'] ?? null) ? $openGraph['description'] : null,
            'image' => is_string($openGraph['image'] ?? null) ? $openGraph['image'] : null,
            'type' => is_string($openGraph['type'] ?? null) ? $openGraph['type'] : null,
            'siteName' => is_string($openGraph['siteName'] ?? null) ? $openGraph['siteName'] : null,
            'url' => is_string($openGraph['url'] ?? null) ? $openGraph['url'] : null,
            'imageWidth' => is_numeric($openGraph['imageWidth'] ?? null) ? (int) $openGraph['imageWidth'] : null,
            'imageHeight' => is_numeric($openGraph['imageHeight'] ?? null) ? (int) $openGraph['imageHeight'] : null,
            'imageAlt' => is_string($openGraph['imageAlt'] ?? null) ? $openGraph['imageAlt'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $twitter
     * @return BeaconTwitterGql
     */
    private static function twitterForGql(array $twitter): array
    {
        /** @var BeaconTwitterGql */
        return [
            'card' => is_string($twitter['card'] ?? null) ? $twitter['card'] : null,
            'title' => is_string($twitter['title'] ?? null) ? $twitter['title'] : null,
            'description' => is_string($twitter['description'] ?? null) ? $twitter['description'] : null,
            'image' => is_string($twitter['image'] ?? null) ? $twitter['image'] : null,
            'site' => is_string($twitter['site'] ?? null) ? $twitter['site'] : null,
        ];
    }

    /**
     * @return list<BreadcrumbItem>
     */
    private static function resolveBreadcrumbs(Entry $entry): array
    {
        $plugin = Plugin::getInstance();
        $site = $entry->getSite();
        $settings = $plugin->siteSettings->getBreadcrumbs($site->id);
        return $plugin->breadcrumbs->getResolved(
            $entry,
            $settings,
            $site->getBaseUrl() ?? '/',
        );
    }
}
