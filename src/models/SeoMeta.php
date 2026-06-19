<?php

namespace anvildev\beacon\models;

/**
 * @phpstan-type HreflangAlternate array{hreflang:string,href:string}
 * @phpstan-type MetaTag array{attr:string, name:string, content:string}
 */
class SeoMeta
{
    public string $title = '';
    public string $description = '';
    public ?string $canonical = null;
    /** @var list<string> */
    public array $robots = [];
    /**
     * Effective AI-usage policy (`allow` / `no-train` / `no-generative-ai` /
     * `no-ai`) resolved entry → section → global. Drives the TDMRep meta tags
     * and the Content-Usage header; its `noai`/`noimageai` tokens are also
     * folded into {@see self::$robots} so the robots tag + X-Robots-Tag carry them.
     */
    public string $aiUsagePolicy = 'allow';
    /** @var array{title?:?string, description?:?string, image?:?string, type?:?string, siteName?:?string, url?:?string, imageWidth?:?int, imageHeight?:?int, imageAlt?:?string, locale?:?string} */
    public array $openGraph = [];
    /** @var array{card?:?string, title?:?string, description?:?string, image?:?string, site?:?string, creator?:?string} */
    public array $twitter = [];

    /**
     * Auto-derived meta tags that can repeat (so they can't live in the
     * name-keyed tag map): `og:locale:alternate` per other propagated locale,
     * and `article:author` per attached author. Rendered after the keyed map.
     *
     * @var list<MetaTag>
     */
    public array $extraMetaTags = [];
    /**
     * Article companion datetimes (ISO-8601). Emitted as `article:*` meta when og:type is `article`.
     *
     * @var array{publishedTime?:string, modifiedTime?: string}|null
     */
    public ?array $articleTimes = null;

    /**
     * `hreflang` / `href` pairs when `beacon.hreflang.enabled` is true.
     *
     * @var list<HreflangAlternate>
     */
    public array $alternates = [];

    /** @var list<array{rel: 'next'|'prev', href: string}> */
    public array $paginationLinkTags = [];

    /** @var array<string,string> */
    public array $sourceMap = [];

    /**
     * Shallow-safe clone for inspection events (listeners must not mutate the cached live instance).
     */
    public function __clone()
    {
        $this->robots = [...$this->robots];
        $this->alternates = array_values(array_map(
            fn(array $row): array => ['hreflang' => $row['hreflang'], 'href' => $row['href']],
            $this->alternates,
        ));
        $this->paginationLinkTags = [...$this->paginationLinkTags];
        $this->sourceMap = [...$this->sourceMap];
        $this->openGraph = [...$this->openGraph];
        $this->twitter = [...$this->twitter];
        $this->extraMetaTags = array_values(array_map(
            static fn(array $row): array => ['attr' => $row['attr'], 'name' => $row['name'], 'content' => $row['content']],
            $this->extraMetaTags,
        ));
        $this->articleTimes = $this->articleTimes !== null ? [...$this->articleTimes] : null;
    }
}
