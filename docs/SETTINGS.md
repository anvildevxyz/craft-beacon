# Settings reference

Every Beacon setting, where to find it in the CP, and its default. Global settings live in `Settings → *`; per-site settings live under each site's CP screen and are stored as DB records (not Project Config) so they can vary per environment.

## Runtime behavior (always on)

Beacon's runtime behaviors have no CP controls. They are always on:

- `craft.beacon.head()` renders meta tags.
- Beacon emits `X-Robots-Tag` + canonical `Link` response headers.
- Sitemap caches auto-regenerate on miss.
- Slug changes create 301 redirects.
- Crawler bot logging records hits.
- **Noindex is automatic** whenever Craft's resolved environment is not
  `production`. To publish indexable pages, set `CRAFT_ENVIRONMENT=production`
  in your environment — Beacon does not maintain its own environment
  override.

If you don't want one of these features, don't install Beacon.

## Global — Settings → General

| Key | Default | Notes |
|---|---|---|
| `titleTemplate` | `'{title}'` | Token template for the SEO title — see [Template tokens](#template-tokens) for the full set (`{title}`, `{siteName}`, `{section}`, …). |
| `descriptionTemplate` | `''` | Same syntax; empty disables a global default description. |
| `defaultSocialImageId` | `null` | Asset id used as the fallback Open Graph / Twitter image when entries don't set their own. Recommended 1200×630. |

> `socialImageTransform` (the Craft transform handle applied to social images; default `'beaconSocial'`, with `'none'` / `'original'` / `'full'` serving the asset untransformed) is a developer-level setting and is **set via `config/beacon.php`, not the Control Panel**.

## Global — Settings → Content defaults

| Key | Default | Notes |
|---|---|---|
| `sectionSeoDefaults` | `[]` | Map: `sectionHandle => ['titleTemplate' => …, 'descriptionTemplate' => …]`. Edited at `/admin/beacon/settings/content`. Precedence: Entry override → Section defaults → Global `titleTemplate` / `descriptionTemplate`. |

### Template tokens

The `titleTemplate` and `descriptionTemplate` values (global and per-section) support these tokens, substituted at render time. The CP editor surfaces them as clickable pills.

| Token | Resolves to |
|---|---|
| `{title}` | The resolved SEO title (entry title, or its override). |
| `{siteName}` | The current site's name. |
| `{section}` | The entry's section name. |
| `{type}` | The entry's entry-type name. |
| `{author}` | The entry author's name (empty when none). |
| `{parent}` | The parent entry's title — Structure sections only (empty when none). |

Entry-derived tokens (`{section}`, `{type}`, `{author}`, `{parent}`) resolve to an empty string outside an entry context, and the heavier lookups (`{author}`, `{parent}`) are only queried when a template actually references them.

## Global — Settings → Organization

| Key | Default | Notes |
|---|---|---|
| `identityType` | `'Organization'` | schema.org `@type` for the identity JSON-LD root. `'Person'`, or an organization type: `Organization`, `Corporation`, `LocalBusiness`, `OnlineStore`, `NewsMediaOrganization`, `EducationalOrganization`, `GovernmentOrganization`, `NGO`. Person shows the Person-only fields in the CP; the rest show the Organization-only fields. |
| `organizationName` | `null` | When empty, Beacon falls back to the current site's name in the rendered JSON-LD. The CP input shows the primary site name as a placeholder. |
| `organizationLogoAssetId` | `null` | Asset id; rendered as `logo.url`. |
| `socialProfiles` | `{}` | Map of `platformKey => profileUrl`. Known keys: `twitter`, `facebook`, `linkedin`, `instagram`, `youtube`, `pinterest`, `tiktok`, `github`, `mastodon`, `bluesky`, `threads`. Feeds Schema.org `sameAs`, the `twitter:site` meta (handle parsed from the Twitter URL), and `craft.beacon.socials()`. |

Plus the rich `identityAdvanced` map (one form section per group on the Organization tab):

| Group | Keys |
|---|---|
| General | `alternateName`, `legalName`, `description` |
| Contact | `email`, `telephone` |
| Postal address (`PostalAddress` node) | `streetAddress`, `addressLocality`, `addressRegion`, `postalCode`, `addressCountry` |
| Geo (`GeoCoordinates` node) | `geoLatitude`, `geoLongitude` (both required to emit) |
| Founding (Organization only) | `foundingDate`, `foundingLocation`, `founder` |
| Contact point (`ContactPoint` node, Organization only) | `contactType`, `contactEmail`, `contactTelephone` |
| Person details (Person only) | `givenName`, `familyName`, `jobTitle`, `birthPlace` |
| Topics | `knowsAbout` — array of subject strings |

### Using social profiles in templates

```twig
<ul class="social-links">
{% for s in craft.beacon.socials() %}
    <li>
        <a href="{{ s.url }}" aria-label="{{ s.label }}">
            {{ s.label }}{% if s.handle %} <span class="handle">@{{ s.handle }}</span>{% endif %}
        </a>
    </li>
{% endfor %}
</ul>
```

Each row from `craft.beacon.socials()` has: `url`, `label` (display name), `handle` (bare handle parsed from the URL, where derivable), and `platform` (the key).

For a single platform, look it up by **key**: `{{ craft.beacon.socialUrl('twitter') }}` returns the configured URL or `null`.

The keys (set in **Settings → Social**, used in `socialUrl()` and the `platform` field):

| Platform | Key |
|---|---|
| X / Twitter | `twitter` |
| Facebook | `facebook` |
| LinkedIn | `linkedin` |
| Instagram | `instagram` |
| YouTube | `youtube` |
| Pinterest | `pinterest` |
| TikTok | `tiktok` |
| GitHub | `github` |
| Mastodon | `mastodon` |
| Bluesky | `bluesky` |
| Threads | `threads` |

For additional `sameAs` profiles beyond the curated platforms (Wikidata, Crunchbase, etc.), add a per-entry or Organization-level schema node via the [Extensibility cookbook](EXTENSIBILITY_COOKBOOK.md).

## Social cards (hardcoded defaults)

Profile URLs are configured under **Settings → Social**. The Open Graph and
Twitter *card* defaults, however, are hardcoded to the values nearly every
project uses:

- `og:type` defaults to `website`, or `article` when the entry's type has
  an Article schema bundle attached.
- `twitter:card` defaults to `summary_large_image` when an OG image is
  present, otherwise `summary`.
- `twitter:site` (`@handle`) is derived from `socialProfiles['twitter']` —
  set the X / Twitter URL under **Settings → Social**.

If you need a different `og:type` on a specific entry, override it on the
per-entry SEO field. The default image and transform handle live on the
General tab (above).

## Hreflang

Hreflang has no CP tab. It is automatic: when Craft has ≥2 enabled sites,
Beacon emits `<link rel="alternate" hreflang>` for every localized entry.
Single-site installs are a cheap no-op.

To opt out, or to point `hreflang="x-default"` at a specific site, use
`config/beacon.php` (see the Config-file overrides section below).

## Pagination (call-site only)

Pagination has no global settings and no CP tab. Every paginated listing
already has to call `craft.beacon.setPagination({...})` in its template, so
the knobs live on that call instead of in a global preset:

```twig
{% paginate entries.limit(10) as pageInfo, paged %}

{% do craft.beacon.setPagination({
    page: pageInfo.currentPage,
    pageCount: pageInfo.totalPages,
    baseUrl: pageInfo.basePageUrl,
    pageParam: pageInfo.pageTrigger,   // default: 'page'
    canonicalMode: 'firstPageCanonical', // default; or 'self'
    appendPageToTitle: false,          // default; or true
}) %}
```

Hardcoded defaults: `pageParam: 'page'`, `canonicalMode: 'firstPageCanonical'`,
`appendPageToTitle: false`. Override per-listing as needed.

`canonicalMode: 'firstPageCanonical'` collapses all paginated URLs to point
at page 1 — most SEO teams prefer this. `'self'` keeps each page's canonical
pointing at itself; in that mode, hreflang alternates are auto-rewritten to
match the page so language signals stay consistent.

## Global — Settings → GEO Markdown

| Key | Default | Notes |
|---|---|---|
| `geoMarkdownEnabled` | `true` | Master switch for the `/geo/export` endpoint and `.md` suffix route. |
| `geoMarkdownFullPageRender` | `true` | **Config-file only** (`config/beacon.php`). When `true`, renders the entry's Twig template and HTML→Markdown converts. When `false`, pulls Markdown from `geoMarkdownBodyFieldHandle`. |
| `geoMarkdownNegotiateAcceptHeader` | `true` | Serve Markdown when the request `Accept: text/markdown`. |
| `geoMarkdownMdSuffixEnabled` | `true` | Register `GET /<uri>.md` routes. |
| `geoMarkdownAutoServeBots` | `true` | Serve Markdown automatically to known AI bots. |
| `geoMarkdownSectionAllowlist` | `[]` | **Config-file only** (`config/beacon.php`). List of section handles. Empty = all sections allowed. |
| `geoMarkdownExcerptLength` | `null` | **Config-file only** (`config/beacon.php`). Optional character cap on the exported Markdown body (word-boundary cut, ellipsis). `null` = export the full content. |
| `geoMarkdownExcerptFallbackToDescription` | `true` | When the body is empty, fall back to the entry's SEO description. |
| `geoMarkdownExcludedClasses` | `[]` | **Config-file only** (`config/beacon.php`). CSS classes whose elements are stripped before conversion. |
| `geoMarkdownFrontMatterDefaults` | `[]` | **Config-file only** (`config/beacon.php`). Site-level front matter keys merged beneath section + entry data. |
| `geoProvenanceSchemaEnabled` | `true` | Auto-emit citation JSON-LD on entry pages with `<link rel="alternate" type="text/markdown">`. |

## Commerce products

Commerce has no dedicated settings tab. When `craftcms/commerce` is
installed, the per-site **Sitemap** CP page (`/admin/beacon/sitemap`)
gains an extra row labeled "Products (Commerce)" — handle `__products__`.
That row behaves identically to a section row: check it to include
product URLs in `sitemap.xml`, set per-row priority and changefreq.

For GEO Markdown, products are eligible whenever:

- `geoMarkdownEnabled` is on (default `true`), and
- the global `geoMarkdownSectionAllowlist` is empty (allow-all) **or**
  contains the `__products__` scope.

This replaces the previous `commerceSitemapEnabled`,
`commerceMarkdownEnabled`, `commerceSitemapPriority` and
`commerceSitemapChangefreq` settings — products now use the same
mechanisms as Entry sections.

## Global — SEO field

| Key | Default | Notes |
|---|---|---|
| `robotsDirectivesEnabled` | `null` | Map of robots-directive opt-ins. `null` exposes the four legacy directives (`noindex`, `nofollow`, `noarchive`, `nosnippet`). Pass an explicit map to control which directives editors can select per-entry. |

### Available robots directives

| Directive | Effect |
|---|---|
| `noindex` | Instructs search engines not to index the page. Beacon also emits `X-Robots-Tag: noindex` in the response header. |
| `nofollow` | Instructs search engines not to follow links on the page. |
| `noarchive` | Prevents search engines from showing a cached copy. |
| `nosnippet` | Prevents search engines from showing a text snippet or video preview in results. |
| `notranslate` | Prevents Google from offering a translation of the page. |

To expose all five directives to editors:

```php
// config/beacon.php
return [
    'robotsDirectivesEnabled' => [
        'noindex'     => true,
        'nofollow'    => true,
        'noarchive'   => true,
        'nosnippet'   => true,
        'notranslate' => true,
    ],
];
```

To expose only `noindex` and `nofollow` (common minimal set):

```php
return [
    'robotsDirectivesEnabled' => [
        'noindex'  => true,
        'nofollow' => true,
    ],
];
```

Directives not listed in the map are hidden from the per-entry SEO field and cannot be selected. Set `null` to use the legacy default (exposes `noindex`, `nofollow`, `noarchive`, `nosnippet`).

## Per-site — Sitemap (`/admin/beacon/sitemap`)

| Key | Default | Notes |
|---|---|---|
| `sections` | `[]` | Section handles to include. |
| `excludeSections` | `[]` | Section handles to exclude (overrides `sections`). |
| `priority` | `0.8` | Default URL priority (`0.0–1.0`). |
| `changefreq` | `'weekly'` | One of `always`, `hourly`, `daily`, `weekly`, `monthly`, `yearly`, `never`. |
| `newsSections` | `[]` | Sections to include in `sitemap-news.xml` (Google News). |
| `sectionSitemap` | `[]` | Per-section overrides: `{ handle: { priority, changefreq } }`. |
| `geoMarkdownFrontMatter` | `[]` | Per-section front-matter overrides for the GEO Markdown export. |

## Per-site — llms.txt (`/admin/beacon/crawlers/llms-txt`)

See [llms.txt configuration](LLMS_TXT.md).

## Per-site — Robots (`/admin/beacon/crawlers/robots`)

| Key | Default | Notes |
|---|---|---|
| `sitemapUrl` | `'auto'` | `'auto'` emits `Sitemap: <baseUrl>/sitemap.xml`; or set an explicit URL. |
| `userAgentRules` | `[]` | List of `{ userAgent, allow[], disallow[] }`. AI-crawler rules are merged in automatically. |

## Breadcrumbs (automatic)

Beacon auto-emits `BreadcrumbList` JSON-LD on every entry page. There is no
CP page.

- **Enabled** is `true` by default. To disable globally, set
  `breadcrumbsEnabled => false` in `config/beacon.php`.
- **Home label** is auto-detected as the title of the entry whose URI is
  `__home__` on the current site (Craft's home-page convention — any Single
  section configured as the homepage). Falls back to `'Home'` if no such
  entry exists. Override per-site via `breadcrumbsHomeLabel` (map of
  `siteHandle => label`) in `config/beacon.php` for translation or rebranding.

## Per-site — Humans / Ads

Toggle + body editor at `/admin/beacon/crawlers/humans-txt` and `/admin/beacon/crawlers/ads-txt`. Ads.txt also accepts an asset picker that overrides the body when set.

## Domain verification meta tags

Beacon does **not** emit Google/Bing/Pinterest/Yandex/Naver/Baidu/Facebook
domain-verification meta tags. Domain ownership is a deployment concern, not
a CMS concern — use DNS TXT records, file upload, or hand-add the meta tag
to your site's layout template.

## Per-site — IndexNow key

The IndexNow ownership-proof key is per-site. There is no CP page; set it
in `config/beacon.php` keyed by site handle:

```php
return [
    'indexNowKeys' => [
        'default' => 'paste-key-here',
        'french'  => 'different-key',
    ],
];
```

The ownership-proof file is served at `/{key}.txt`. A legacy DB column
(`beacon_webmaster_settings.indexNowKey`) is still read as a fallback.

## Config-file overrides (`config/beacon.php`)

A handful of power-user knobs intentionally have no CP control. Configure
them from a `config/beacon.php` file in your project. The file returns a
plain associative array; values found here override the DB Settings model
on every request. Only the keys below are honored — other keys are ignored
so the file can't accidentally clobber DB-managed identity / template data.

**Copy the shipped [`src/config.php`](../src/config.php) to your project's
`config/beacon.php` as a starting point** — it documents every key with its
default. The honored keys are:

| Key | Purpose |
|---|---|
| `hreflangEnabled` | Emit hreflang alternates (auto-on with ≥2 sites). |
| `hreflangXDefaultSiteHandle` | Site handle that feeds `hreflang="x-default"`. |
| `indexNowEnabled` | IndexNow auto-submit on entry save. |
| `indexNowKeys` | Map of site handle → IndexNow key (falls back to the legacy `beacon_webmaster_settings` DB column). |
| `staleThresholdDays` | "Stale" badge threshold on the redirects index. |
| `botLogRetentionDays` | GC threshold for `beacon_bot_log`. |
| `log404s` | Record unhandled 404s for the suggested-redirects screen. |
| `log404RetentionDays` | GC threshold for the 404 log. |
| `metaCacheDuration` | Meta cache seconds; `null` = request-scoped only. |
| `geoMarkdownBodyFieldHandle` | Body field handle when full-page render is off. |
| `breadcrumbsEnabled` | Emit BreadcrumbList JSON-LD. |
| `breadcrumbsHomeLabel` | Per-site first-crumb label (map of site handle → label). |
| `schemaTypes` | Extra schema.org types for the SEO field's "Add schema" modal (see [Extensibility cookbook](EXTENSIBILITY_COOKBOOK.md) → Recipe 9). |
| `fullSchemaCatalogue` | Ship the full ~900-type schema.org catalogue in the dropdown. |

The file is read via `Craft::$app->getConfig()->getConfigFromFile('beacon')`,
so you can use environment-aware multi-environment configs the same way
you do for `config/general.php`.

## Environment variables

| Var | Effect |
|---|---|
| `BEACON_META_DEBUG=1` | Log resolved meta to the Craft log + emit `Server-Timing` headers, regardless of `devMode`. |

## Permissions

CP user permissions and GraphQL schema component handles are documented separately — see [PERMISSIONS.md](PERMISSIONS.md).

## Where settings are stored

- **Global `Settings` model** → Project Config under `beacon.*`. Syncs across environments via `project.yaml`.
- **Per-site DB records** (Sitemap, Llms, Humans, Ads, Robots, Webmaster) → DB only. Vary per environment.
- **Schema bundles, redirects, tracking scripts, AI crawler rules** → DB-backed; tracking scripts also Project-Config-synced under `beacon.trackingScripts.{uid}`.
