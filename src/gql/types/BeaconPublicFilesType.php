<?php

namespace anvildev\beacon\gql\types;

use anvildev\beacon\Plugin;
use craft\models\Site;
use GraphQL\Type\Definition\Type;

/**
 * The rendered bodies of Beacon's public discovery files for one site,
 * fetched via the `beaconFiles` root query (issue #37). Headless frontends
 * fetch these and re-serve them from their own domain instead of routing
 * `/sitemap.xml` & co. to the Craft origin.
 *
 * Every field resolves lazily — only the files in the selection set are
 * rendered. URLs inside the documents (sitemap `<loc>`, the index's chunk
 * URLs, robots' `Sitemap:` line) use the site's configured base URL.
 *
 * Source: `['site' => Site]`, provided by the root resolver.
 *
 * @phpstan-import-type GqlFieldDefinitionMap from \anvildev\beacon\gql\types\BeaconObjectType
 */
class BeaconPublicFilesType extends BeaconObjectType
{
    public static function getName(): string
    {
        return 'BeaconPublicFiles';
    }

    protected static function getDescription(): string
    {
        return 'Rendered bodies of Beacon\'s public files (robots.txt, sitemap.xml, llms.txt, …) for one site. Each field renders on selection.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'robotsTxt' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'robots.txt body: user-agent rules + enabled AI-crawler rules + Content-Signal lines.',
                'resolve' => static fn(array $source): string => Plugin::$plugin->publicFiles->robotsTxt(self::site($source)),
            ],
            'sitemap' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Master sitemap.xml — a urlset, or a sitemap index when chunked (chunk URLs point at sitemap-N.xml; fetch their content via sitemapPart).',
                'resolve' => static fn(array $source): string => Plugin::$plugin->publicFiles->sitemapMaster(self::site($source)),
            ],
            'sitemapPart' => [
                'type' => Type::string(),
                'args' => [
                    'part' => ['type' => Type::nonNull(Type::int()), 'description' => '1-based chunk number, matching sitemap-N.xml.'],
                ],
                'description' => 'A chunked sub-sitemap urlset; null when the sitemap isn\'t chunked or the part is out of range.',
                'resolve' => static fn(array $source, array $args): ?string => Plugin::$plugin->publicFiles->sitemapPart(self::site($source), (int) $args['part']),
            ],
            'llmsTxt' => [
                'type' => Type::string(),
                'description' => 'llms.txt index body (markdown); null when llms.txt is disabled for the site.',
                'resolve' => static fn(array $source): ?string => Plugin::$plugin->publicFiles->llmsTxt(self::site($source)),
            ],
            'llmsFullTxt' => [
                'type' => Type::string(),
                'description' => 'llms-full.txt body trimmed to the configured token budget; null when disabled or empty.',
                'resolve' => static fn(array $source): ?string => Plugin::$plugin->publicFiles->llmsFull(self::site($source))?->markdown,
            ],
            'adsTxt' => [
                'type' => Type::string(),
                'description' => 'ads.txt body; null when disabled or empty.',
                'resolve' => static fn(array $source): ?string => Plugin::$plugin->publicFiles->adsTxt(self::site($source)),
            ],
            'humansTxt' => [
                'type' => Type::string(),
                'description' => 'humans.txt body; null when disabled or empty.',
                'resolve' => static fn(array $source): ?string => Plugin::$plugin->publicFiles->humansTxt(self::site($source)),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $source
     */
    private static function site(array $source): Site
    {
        $site = $source['site'] ?? null;
        assert($site instanceof Site);
        return $site;
    }
}
