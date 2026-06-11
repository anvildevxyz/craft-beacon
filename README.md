# Beacon

**SEO and GEO for Craft 5** — meta, schema, redirects, llms.txt, AI crawler controls. CP-first, API-first, PHPBench-verified performance budgets on every PR.

## Why Beacon

- **Built for AI search.** llms.txt with trust block, AI crawler rules, E-E-A-T author system, per-URL Markdown export.
- **Fully CP-driven.** Every setting has a UI. No YAML required.
- **Headless-ready.** Native GraphQL `beacon` field with a lazy resolver.
- **Performance you can verify.** PHPBench-measured, CI-enforced budgets on every PR.

## Performance

| Operation | Budget | Verified (μs median) |
|---|---|---|
| Redirect lookup (exact match) | < 1ms | ~0.1μs |
| Redirect lookup (exact miss) | < 1ms | ~0.1μs |
| Redirect lookup (glob single-segment) | < 5ms | ~1.1μs |
| Redirect lookup (glob multi-segment) | < 5ms | ~1.1μs |
| Redirect lookup (regex) | < 5ms | ~1.0μs |

Numbers from `bench/baseline.json` (PHPBench, PHP 8.4). CI enforces the budget on every PR.

## Requirements

- PHP 8.2 or newer
- Craft CMS 5.0 or newer
- Craft Commerce (optional) — enables product URLs in the sitemap

## Install

```bash
composer require anvildev/craft-beacon
php craft plugin/install beacon
```

## Uninstall

```bash
php craft plugin/uninstall beacon
```

Uninstalling drops **all** Beacon tables and their data — including redirects,
short links, authors, schema bundles, per-site text-surface config, and the
bot/404/GEO-score history. Export anything you want to keep (e.g. the redirects
CSV export under **/admin/beacon/redirects**) before uninstalling.

## Quick start

After install, visit `/admin/beacon` for the dashboard. All configuration lives in the Control Panel — no project-config YAML required.

First-run path:

1. **Open** `/admin/beacon`.
2. **Set your identity** under **Settings → Organization** (name, logo, social profiles) — this feeds JSON-LD sitewide.
3. **Add the Beacon SEO field** to your entry types' field layouts so editors get per-entry meta + live previews.
4. **Add `head()` to your layout** (below) so meta, Open Graph, and JSON-LD render.
5. **Go live:** set `CRAFT_ENVIRONMENT=production`. Outside production Beacon emits `noindex` on every page by design — see [Troubleshooting](#troubleshooting).

In your Twig layout `<head>`:

```twig
{{ craft.beacon.head() }}
```

If you need manual composition, placement-specific tracking helpers are also available:

```twig
{{ craft.beacon.trackingFor('head') }}
{{ craft.beacon.trackingFor('bodyStart') }}
{{ craft.beacon.trackingFor('bodyEnd') }}
{# optional explicit env override #}
{{ craft.beacon.trackingFor('head', 'staging') }}
```

That renders title, description, canonical, robots, Open Graph + Twitter Card meta (`og:*` / `twitter:*`), optional `article:*` times when `og:type` is `article`, and JSON-LD. For the full reference — every function, every parameter, call-order rules, headless usage — see [docs/TWIG_API.md](docs/TWIG_API.md).

For social images, register a Craft image transform named `beaconSocial` (~1200×630) — Beacon applies it by default. To use a different handle (or `none` / `original` / `full` to serve the native asset URL), set `socialImageTransform` in `config/beacon.php`.

## CP screens

Beacon is fully CP-driven. All configuration lives in DB tables managed via `/admin/beacon`:

- **Dashboard** — overview + quick actions
- **Authors** — Author elements for E-E-A-T schemas
- **Redirects** — 301/302 rules
- **Schemas** — JSON-LD bundles per entry type (Article, Product, Recipe, HowTo, FAQPage, Review)
- **Sitemap** — per-site sitemap config (sections, priority, changefreq)
- **llms.txt** — per-site llms.txt config (enabled, summary, sections, trust block)
- **Robots** — per-site robots.txt config (User-agent rules, sitemap URL)
- **humans.txt** — per-site humans.txt body (enabled toggle + free-form body)
- **ads.txt** — per-site ads.txt content (enabled toggle + asset picker with body fallback)
- **AI crawlers** — global AI crawler rules + AI bot list (with restore-defaults)
- **Settings** — title template, Organization (JSON-LD), Open Graph + Twitter defaults, behavior toggles (auto-redirect, stale threshold, bot logging, bot log retention), hreflang, GEO Markdown export, Commerce sitemap, Pagination

## Public endpoints

Beacon registers these site URLs automatically (overridable in `config/routes.php`):

- **`GET /sitemap.xml`** — single `urlset` when the merged URL count is at or below the per-file limit; otherwise a `sitemapindex` listing `sitemap-1.xml`, `sitemap-2.xml`, …
- **`GET /sitemap-N.xml`** — child `urlset` for part *N* when chunked
- **`GET /sitemap-news.xml`** — Google News sitemap (entries published within the last 48h, per-site News-sections config)
- **`GET /sitemap-images.xml`** / **`GET /sitemap-videos.xml`** — image and video sitemaps built from related assets
- **`GET /robots.txt`** — per-site User-agent rules + global AI crawler rules + sitemap pointer
- **`GET /llms.txt`** — markdown summary of selected sections, with optional trust block (policy URL, license URL, contact email, preferred attribution). Also served at **`/.well-known/llms.txt`**.
- **`GET /llms-full.txt`** — the optional long-form companion body (per-site `fullBody`, opt-in). Also served at **`/.well-known/llms-full.txt`**.
- **`GET /feed/<section>.json`** / **`GET /feed/<section>.atom`** — JSON Feed 1.1 and Atom 1.0 feeds for a section
- **`GET /humans.txt`** — free-form humans.txt body (per-site, opt-in)
- **`GET /ads.txt`** — IAB Tech Lab ads.txt format (per-site, served from asset or fallback body)
- **`GET /beacon/schemamap.json`** — site-level JSON-LD graph listing every public entry as a `WebPage` under a site `Collection`, plus `WebSite` + `Organization` identity nodes (a one-request discovery surface for AI agents)
- **`GET /geo/export?id=<id>`** (and `GET /<uri>.md`) — per-URL Markdown export, with optional `Accept: text/markdown` negotiation

Sitemap extensibility: hook `\anvildev\beacon\Plugin::EVENT_REGISTER_SITEMAP_URLS` (event class `\anvildev\beacon\events\RegisterSitemapUrlsEvent` → `pushUrl($loc, $lastmod?, $changefreq?, $priority?)`). Duplicate `loc` values: last registration wins.

## Headless / GraphQL

Beacon registers a `beacon` field on Craft's `EntryInterface`:

```graphql
{
  entries(section: "blog", limit: 10) {
    title
    url
    beacon {
      title
      description
      canonical
      robots
      articlePublishedTime
      articleModifiedTime
      breadcrumbs { name url }
      openGraph { title description image type siteName url imageWidth imageHeight imageAlt }
      twitter { card title description image site }
      schemas    # JSON-LD strings (client must JSON.parse each)
      schemaNodes {
        type
        rawJson
        article { headline description datePublished dateModified mainEntityOfPage }
        product { name description sku brandName offersPrice offersCurrency offersAvailability }
        breadcrumbList { itemListElement { position name item } }
      }
    }
  }
}
```

The resolver is **lazy** — entries that don't include `beacon` in the query never touch the plugin.

**Schema-graph scope:** GraphQL `beacon.schemas` and `beacon.schemaNodes` return the CP-configured schema bundles (Article / Product / Recipe / HowTo / FAQPage / Review) plus any per-entry `schemaAddons` from the Beacon SEO field. The auto-emitted nodes that appear in the HTML `<head>` (BreadcrumbList from the breadcrumb chain, the WebPage / WebSite identity pair, the Organization / Person node, the GEO-provenance citations node) are **HTML-only** in this release. Headless clients can rebuild them client-side from `beacon.breadcrumbs { name url }` plus a static Organization JSON-LD constant in their layout. Full parity (single schema-graph builder used by both HTML and GraphQL paths) is tracked for a future release.

### GraphQL: redirects & 404 log

Two read-only top-level queries are exposed when the corresponding schema components are granted on a token:

- `beaconRedirects:read` enables `beaconRedirects(siteId, source, type, enabled, search, limit, offset)` and `beaconRedirect(id)`
- `beaconRedirect404s:read` enables `beaconRedirect404s(siteId, handled, limit)`

```graphql
{
  beaconRedirects(siteId: 1, source: "manual", search: "/blog") {
    id
    sourceUri
    targetUri
    statusCode
    type
    queryStringMode
    enabled
    hits
    lastHit
    sortOrder
  }
  beaconRedirect404s(siteId: 1, limit: 20) {
    id
    uri
    hits
    lastSeen
    referer
  }
}
```

Both `BeaconRedirect` and `BeaconRedirect404` are queryable via `__type` introspection. The plural queries return non-null lists of non-null items — clients can skip null checks. The singular `beaconRedirect(id: …)` returns null when no row matches.

### GraphQL: short links

`beaconShortLinks(siteId, enabled, search, limit)` exposes short links
read-only, gated by `beaconShortLinks:read`. Pass `siteId` to return only
the links live on that site. Returns the `BeaconShortLink` type (id,
propagationMethod, slug, destination, statusCode, enabled, clicks,
lastClicked, expiresAt, …).

### GraphQL: GEO content score

The `beacon` field exposes a `geoScore` sub-field that returns the
composite 0–100 GEO score plus per-pillar breakdown. The resolver is
lazy — entries that don't include `geoScore` in the selection set never
touch the score table — and the field is gated by the
`beaconGeoScore:read` schema component (returns `null` without it).

```graphql
{
  entries(section: "blog", limit: 5) {
    title
    beacon {
      geoScore {
        score              # 0–100 composite, calibrated to published GEO research
        weakestPillar      # handle of the lowest-scoring pillar (the one to fix first)
        computedAt         # ISO-8601; may lag editor saves by one queue-runner cycle
        pillars {
          handle           # freshnessBanding, entityCompleteness, claimBasedHeadings,
                           # chunkability, factDensity, outboundCitationDensity
          score            # 0–10
          band             # top | good | low | stale
          notes            # actionable feedback strings (e.g. "Section 'Setup' has a
                           # 12-word lead — expand to 40–75 words.")
        }
      }
    }
  }
}
```

A live smoke is in `bench/gql-geoscore.sh` (set `TOKEN`, `ENTRY_ID`, optional `ANON_TOKEN`).

### GraphQL writes (Beacon SEO field)

Beacon does not register custom GraphQL mutations. For headless authoring, use Craft's built-in entry mutations and set your Beacon SEO field handle as an object input.

Example payload shape (assuming field handle `seo`):

```graphql
mutation SaveEntrySeo($id: ID!, $siteId: Int!, $title: String!, $seo: seo_FieldInput) {
  save_entries_default_Entry(
    id: $id
    siteId: $siteId
    title: $title
    seo: $seo
  ) {
    id
    ... on entries_default_Entry {
      seo
    }
  }
}
```

`$seo` can include keys such as `title`, `description`, `canonical`, `robots`, `ogImageId`, `authorIds`, and `schemaAddons`.

See additional docs:

- [Config file example](src/config.php) — file-only options + code-locked overrides
- [Twig API reference](docs/TWIG_API.md)
- [Permissions reference](docs/PERMISSIONS.md)
- [Settings reference](docs/SETTINGS.md)
- [Authors & E-E-A-T](docs/AUTHORS.md)
- [llms.txt configuration](docs/LLMS_TXT.md)
- [GEO content scoring](docs/GEO_CONTENT_SCORING.md) — the 0–100 score, the six pillars, and how to tune them
- [GEO operational playbook](docs/GEO_OPERATIONAL_PLAYBOOK.md)
- [Redirect CSV import](docs/REDIRECT_IMPORT.md) — CSV format, column reference, validation rules, query-string modes
- [Redirect 404 precedence](docs/redirect-404-precedence.md)
- [Beacon extensibility cookbook](docs/EXTENSIBILITY_COOKBOOK.md)
- [Tracking provider extensibility cookbook](docs/tracking-provider-cookbook.md)
- [Security policy](docs/SECURITY.md)

## CLI commands

| Command | What it does |
|---|---|
| `beacon/cache/regenerate-all` | Warm every text/sitemap surface in one pass (ideal post-deploy hook). |
| `beacon/cache/regenerate-sitemap` | Rebuild the sitemap caches. |
| `beacon/cache/regenerate-llms-txt` | Rebuild the llms.txt caches. |
| `beacon/cache/flush` | Clear Beacon's render caches. |
| `beacon/markdown/generate` | Pre-generate the per-URL GEO Markdown exports. |
| `beacon/markdown/clear` | Clear generated Markdown. |
| `beacon/redirects/import <csvFile>` | Import redirects from a CSV file. |
| `beacon/redirects/audit` | Report redirect problems (loops, chains). |
| `beacon/redirects/prune404-log` | Prune the 404 log (`--site`, `--threshold-days`). |
| `beacon/index-now/generate-key` | Generate a per-site IndexNow key. |
| `beacon/index-now/submit <url>` | Submit one URL to IndexNow (`--site`). |
| `beacon/index-now/section <handle>` | Submit every URL in a section. |
| `beacon/index-now/all` | Submit all public URLs. |

## Whisper coexistence boundary

When Beacon and Whisper are installed together, **Beacon is the canonical redirect owner**.

- Use Beacon Redirects (`/admin/beacon/redirects`) as the single redirect manager.
- In Whisper settings, set `redirectsEnabled = false` to avoid double 404 listeners.
- Keep Whisper focused on link intelligence/reports, not redirect resolution.

Rationale: both plugins have 404 interceptors; running both creates ambiguous redirect chains and support risk.

## Troubleshooting

- **Every page renders `noindex`.** This is by design outside production — Beacon emits `noindex` whenever Craft's resolved environment is not `production`. Set `CRAFT_ENVIRONMENT=production` to publish indexable pages.
- **`/llms.txt`, `/humans.txt`, or `/ads.txt` returns 404.** These surfaces are per-site and opt-in. Enable them under the matching CP screen (**llms.txt** / **humans.txt** / **ads.txt**) for that site.
- **The social image isn't showing.** Register a Craft image transform named `beaconSocial` (~1200×630) — Beacon applies it by default — and set a fallback image under **Settings → Open Graph**. To use a different transform handle, or `none` / `original` / `full` to skip the transform, set `socialImageTransform` in `config/beacon.php`.
- **The GEO score chip never appears.** Scores are computed by a queue job — make sure the queue runner is processing. Confirm the entry's section is in the GEO score allowlist (an empty allowlist means all sections). See [GEO content scoring](docs/GEO_CONTENT_SCORING.md).
- **A section is missing from the sitemap.** Check that the section is enabled under **Sitemap** settings and that its entries are live and not set to `noindex`.

## Security

Found a vulnerability? See [docs/SECURITY.md](docs/SECURITY.md) for responsible-disclosure instructions.

## Roadmap

See [`docs/ROADMAP.md`](docs/ROADMAP.md) for the prioritized post-v1 feature list.

## Status

Stable. APIs are frozen and follow semver. See [`CHANGELOG.md`](CHANGELOG.md) for release history.

## License

Proprietary. See [LICENSE.md](LICENSE.md).
