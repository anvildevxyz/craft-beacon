<?php

namespace anvildev\beacon\gql;

/**
 * PHPStan array-shape aliases for Beacon GraphQL resolvers and query rows.
 * Import via `@phpstan-import-type Foo from \anvildev\beacon\gql\GqlArrayShapes`.
 *
 * @phpstan-import-type BreadcrumbItem from \anvildev\beacon\types\ArrayShapes
 * @phpstan-import-type HreflangAlternate from \anvildev\beacon\models\SeoMeta
 *
 * @phpstan-type JsonLdNode array<string, mixed>
 *
 * @phpstan-type BeaconOpenGraphGql array{
 *     title: ?string,
 *     description: ?string,
 *     image: ?string,
 *     type: ?string,
 *     siteName: ?string,
 *     url: ?string,
 *     imageWidth: ?int,
 *     imageHeight: ?int,
 *     imageAlt: ?string,
 * }
 *
 * @phpstan-type BeaconTwitterGql array{
 *     card: ?string,
 *     title: ?string,
 *     description: ?string,
 *     image: ?string,
 *     site: ?string,
 * }
 *
 * @phpstan-type BeaconSchemaListItemGql array{position: ?int, name: ?string, item: ?string}
 *
 * @phpstan-type BeaconSchemaArticleGql array{
 *     headline: ?string,
 *     description: ?string,
 *     datePublished: ?string,
 *     dateModified: ?string,
 *     mainEntityOfPage: ?string,
 * }
 *
 * @phpstan-type BeaconSchemaProductGql array{
 *     name: ?string,
 *     description: ?string,
 *     sku: ?string,
 *     brandName: ?string,
 *     offersPrice: ?string,
 *     offersCurrency: ?string,
 *     offersAvailability: ?string,
 * }
 *
 * @phpstan-type BeaconSchemaBreadcrumbListGql array{itemListElement: list<BeaconSchemaListItemGql>}
 *
 * @phpstan-type BeaconSchemaNodeGql array{
 *     type: ?string,
 *     rawJson: string,
 *     article: ?BeaconSchemaArticleGql,
 *     product: ?BeaconSchemaProductGql,
 *     breadcrumbList: ?BeaconSchemaBreadcrumbListGql,
 * }
 *
 * @phpstan-type GeoScoreGqlSource array{
 *     __beaconElementId?: int,
 *     __beaconSiteId?: int,
 * }
 *
 * @phpstan-type BeaconSeoMetaGqlSource array{
 *     title: string,
 *     description: string,
 *     canonical: ?string,
 *     robots: list<string>,
 *     articlePublishedTime: ?string,
 *     articleModifiedTime: ?string,
 *     alternates: list<HreflangAlternate>,
 *     breadcrumbs: list<BreadcrumbItem>,
 *     openGraph: BeaconOpenGraphGql,
 *     twitter: BeaconTwitterGql,
 *     schemas: list<string>,
 *     schemaNodes: list<BeaconSchemaNodeGql>,
 *     __beaconElementId: int,
 *     __beaconSiteId: int,
 * }
 *
 * @phpstan-type BeaconRedirectGqlRow array{
 *     id: int|string,
 *     propagationMethod: string,
 *     sourceUri: string,
 *     targetUri: string,
 *     statusCode: int|string,
 *     type: string,
 *     queryStringMode: string,
 *     hits: int|string,
 *     lastHit: ?string,
 *     source: string,
 *     sortOrder: int|string,
 *     elementId: int|string|null,
 *     note: ?string,
 *     dateCreated: string,
 *     dateUpdated: string,
 *     enabled: bool|int|string,
 * }
 *
 * @phpstan-type BeaconShortLinkGqlRow array{
 *     id: int|string,
 *     propagationMethod: string,
 *     slug: string,
 *     destination: string,
 *     statusCode: int|string,
 *     clicks: int|string,
 *     lastClicked: ?string,
 *     expiresAt: ?string,
 *     note: ?string,
 *     dateCreated: string,
 *     dateUpdated: string,
 *     enabled: bool|int|string,
 * }
 *
 * @phpstan-type BeaconRedirect404GqlRow array{
 *     id: int|string,
 *     siteId: int,
 *     uri: string,
 *     hits: int|string,
 *     firstSeen: string,
 *     lastSeen: string,
 *     referer: ?string,
 *     handled: bool,
 * }
 */
final class GqlArrayShapes
{
    private function __construct()
    {
    }
}
