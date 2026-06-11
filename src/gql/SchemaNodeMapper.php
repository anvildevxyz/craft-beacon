<?php

namespace anvildev\beacon\gql;

/**
 * Maps raw schema.org JSON-LD nodes to the typed GraphQL projections on
 * {@see \anvildev\beacon\gql\types\SchemaNodeType}.
 *
 * @phpstan-import-type BeaconSchemaListItemGql from \anvildev\beacon\gql\GqlArrayShapes
 * @phpstan-import-type BeaconSchemaNodeGql from \anvildev\beacon\gql\GqlArrayShapes
 * @phpstan-import-type JsonLdNode from \anvildev\beacon\gql\GqlArrayShapes
 */
final class SchemaNodeMapper
{
    /**
     * @param JsonLdNode $schema
     * @return BeaconSchemaNodeGql
     */
    public static function map(array $schema, string $rawJson): array
    {
        $type = self::schemaType($schema);
        return [
            'type' => $type,
            'rawJson' => $rawJson,
            'article' => self::isArticleType($type) ? [
                'headline' => self::asString($schema['headline'] ?? null),
                'description' => self::asString($schema['description'] ?? null),
                'datePublished' => self::asString($schema['datePublished'] ?? null),
                'dateModified' => self::asString($schema['dateModified'] ?? null),
                'mainEntityOfPage' => self::asString($schema['mainEntityOfPage'] ?? null),
            ] : null,
            'product' => $type === 'Product' ? [
                'name' => self::asString($schema['name'] ?? null),
                'description' => self::asString($schema['description'] ?? null),
                'sku' => self::asString($schema['sku'] ?? null),
                'brandName' => self::extractBrandName($schema),
                'offersPrice' => self::extractOfferString($schema, 'price'),
                'offersCurrency' => self::extractOfferString($schema, 'priceCurrency'),
                'offersAvailability' => self::extractOfferString($schema, 'availability'),
            ] : null,
            'breadcrumbList' => $type === 'BreadcrumbList' ? [
                'itemListElement' => self::extractListItems($schema),
            ] : null,
        ];
    }

    /**
     * @param JsonLdNode $schema
     */
    public static function encode(array $schema): string
    {
        $json = json_encode($schema, JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '{}';
    }

    /**
     * @param JsonLdNode $schema
     */
    private static function schemaType(array $schema): ?string
    {
        $type = $schema['@type'] ?? null;
        if (is_array($type)) {
            foreach ($type as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    return $candidate;
                }
            }
            return null;
        }
        return is_string($type) && $type !== '' ? $type : null;
    }

    private static function isArticleType(?string $type): bool
    {
        return in_array($type, ['Article', 'NewsArticle', 'BlogPosting'], true);
    }

    private static function asString(string|int|float|bool|null $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param JsonLdNode $schema
     */
    private static function extractBrandName(array $schema): ?string
    {
        $brand = $schema['brand'] ?? null;
        if (is_string($brand) && $brand !== '') {
            return $brand;
        }
        return is_array($brand) ? self::asString($brand['name'] ?? null) : null;
    }

    /**
     * @param JsonLdNode $schema
     */
    private static function extractOfferString(array $schema, string $key): ?string
    {
        $offers = $schema['offers'] ?? null;
        return is_array($offers) ? self::asString($offers[$key] ?? null) : null;
    }

    /**
     * @param JsonLdNode $schema
     * @return list<BeaconSchemaListItemGql>
     */
    private static function extractListItems(array $schema): array
    {
        $items = $schema['itemListElement'] ?? null;
        if (!is_array($items)) {
            return [];
        }

        /** @var list<BeaconSchemaListItemGql> */
        return array_values(array_map(
            static fn(array $item): array => [
                'position' => isset($item['position']) && is_numeric($item['position']) ? (int) $item['position'] : null,
                'name' => self::asString($item['name'] ?? null),
                'item' => self::asString($item['item'] ?? null),
            ],
            array_filter($items, 'is_array'),
        ));
    }
}
