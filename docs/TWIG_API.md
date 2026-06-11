# Beacon Twig API Reference

All Beacon functions are accessible via the `craft.beacon` variable in any Twig template.

---

## Quick start — minimal layout

```twig
<!doctype html>
<html lang="{{ craft.app.language }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  {{ craft.beacon.head() }}
</head>
<body>
  {{ craft.beacon.bodyStart() }}

  {# …page content… #}

  {{ craft.beacon.bodyEnd() }}
</body>
</html>
```

Call `head()`, `bodyStart()`, and `bodyEnd()` **once each per request** in your base layout. Call any `set*()` / `add*()` overrides **before** `head()` — the first call to `head()` locks the resolved result.

---

## Output functions

### `craft.beacon.head()` → `Markup`

Renders and returns the complete SEO `<head>` block as safe HTML. Output includes, in order:

| Tag / header | Condition |
|---|---|
| `<title>` | Always |
| `<link rel="canonical">` | When a canonical URL is resolved |
| `<link rel="alternate" hreflang="…">` | Multi-site hreflang enabled (one per propagated locale + `x-default`) |
| `<link rel="alternate" type="text/markdown">` | GEO Markdown discovery link enabled |
| `<link rel="prev">` / `<link rel="next">` | `setPagination()` called with page info |
| `<meta name="description">` | When a description is resolved |
| `<meta name="robots">` | When robots directives are set |
| Open Graph (`og:*`) | Title, description, type, URL, image, site name, locale |
| `og:locale:alternate` | One per additional propagated locale |
| Twitter Card (`twitter:*`) | Card type, title, description, image, site, creator |
| `article:published_time` / `article:modified_time` | When `og:type=article` and dates are available |
| `article:author` | One per attached Beacon author when `og:type=article` |
| `<script type="application/ld+json">` | JSON-LD graph (bundle schemas + identity + GEO provenance) |
| `<script type="application/ld+json">` | BreadcrumbList (when breadcrumbs are enabled and resolved) |
| Tracking scripts | Providers configured for "head" placement |

HTTP headers also set by `head()`:
- `X-Robots-Tag` — mirrors the robots meta tag
- `Link: <url>; rel="canonical"` — mirrors the canonical link tag
- `Server-Timing: beacon-*` — timing breakdown when `BEACON_META_DEBUG=1` or `devMode` is on

**Error handling:** If anything throws inside `head()` (e.g. an event listener error, a malformed schema), it degrades to a minimal `<title>` fallback and logs a warning. It never aborts template rendering.

---

### `craft.beacon.bodyStart()` → `Markup`

Renders tracking scripts configured for the **Body Start** placement. Place immediately after the opening `<body>` tag.

Returns an empty string in CP requests, preview mode, and console contexts.

---

### `craft.beacon.bodyEnd()` → `Markup`

Renders tracking scripts configured for the **Body End** placement. Place just before `</body>`.

Returns an empty string in CP requests, preview mode, and console contexts.

---

### `craft.beacon.trackingFor(placement, env?)` → `Markup`

Renders tracking scripts for a custom placement or conditionally for a specific environment.

```twig
{# render scripts for a named placement #}
{{ craft.beacon.trackingFor('afterNav') }}

{# only when CRAFT_ENVIRONMENT matches 'production' #}
{{ craft.beacon.trackingFor('head', 'production') }}
```

| Parameter | Type | Description |
|---|---|---|
| `placement` | `string` | Placement identifier as configured in **Settings → Tracking → Scripts** |
| `env` | `string\|null` | Optional. When set, only renders scripts whose environment matches this string |

Returns an empty string in CP, preview, and console contexts.

---

## Meta overrides

All overrides **must be called before `head()`** in the same request. They take effect on the first `head()` call and are ignored afterwards.

### `craft.beacon.set(key, value)`

Overrides a single property on the resolved [`SeoMeta`](#seometa-properties) object.

```twig
{# Override title and description for a custom template #}
{% do craft.beacon.set('title', entry.customHeadline ~ ' | ' ~ siteName) %}
{% do craft.beacon.set('description', entry.summary) %}
{% do craft.beacon.set('robots', ['noindex', 'nofollow']) %}
```

Valid keys and their types are listed in [SeoMeta properties](#seometa-properties). An override with the wrong type is silently dropped and a warning is logged — it will not crash `head()`.

---

### `craft.beacon.setTag(name, content)`

Overrides or adds a single meta tag by name. Accepts any tag name; `og:*` and `article:*` names get `property=` attribute, all others get `name=`.

```twig
{# Add a custom tag not managed by Beacon #}
{% do craft.beacon.setTag('theme-color', '#1a1a2e') %}

{# Override the Open Graph image for this entry #}
{% do craft.beacon.setTag('og:image', socialImage.url) %}

{# Empty content suppresses the tag (same as removeTag) #}
{% do craft.beacon.setTag('og:image', '') %}
```

---

### `craft.beacon.removeTag(name)`

Suppresses a specific tag from output entirely.

```twig
{# Suppress twitter:image on print-layout pages #}
{% do craft.beacon.removeTag('twitter:image') %}
{% do craft.beacon.removeTag('og:image') %}
```

---

### `craft.beacon.addSchema(schemaNode)`

Injects a one-off JSON-LD node into the schema graph for the current request. The node is merged with Beacon's auto-generated graph and emitted in the same `<script type="application/ld+json">` block.

```twig
{% do craft.beacon.addSchema({
  '@context': 'https://schema.org',
  '@type': 'FAQPage',
  'mainEntity': entry.faqItems.map(item => {
    '@type': 'Question',
    'name': item.question,
    'acceptedAnswer': {
      '@type': 'Answer',
      'text': item.answer
    }
  })
}) %}
```

Call before `head()`. Multiple `addSchema()` calls accumulate — nodes are appended in call order.

For complex customisation (e.g. modifying Beacon's own nodes), use the PHP event `Plugin::EVENT_DEFINE_SCHEMAS` from a module or plugin instead.

---

### `craft.beacon.setPagination(config)`

Configures canonical URL, hreflang rewrites, and `rel=prev/next` link tags for paginated listing pages. Call before `head()`.

```twig
{# In a listing template #}
{% set entries = craft.entries.section('blog').all() %}
{% set pageInfo = craft.app.request.getPageNum() %}

{% do craft.beacon.setPagination({
  page: pageInfo,
  pageCount: entries.count() // 10,
  baseUrl: craft.app.request.getAbsoluteUrl() | split('?')[0],
  pageParam: 'page',
  canonicalMode: 'firstPageCanonical',
  appendPageToTitle: true,
}) %}
```

| Key | Type | Default | Description |
|---|---|---|---|
| `page` | `int` | `1` | Current page number (1-based) |
| `pageCount` | `int\|null` | `null` | Total page count. Required for `rel=next` to be emitted |
| `baseUrl` | `string` | `''` | The base URL without page parameters. **Required.** Without it, pagination overrides are silently skipped |
| `pageParam` | `string` | `'page'` | Query parameter name appended for pages > 1 |
| `canonicalMode` | `'firstPageCanonical'\|'self'` | `'firstPageCanonical'` | `firstPageCanonical` sets the same canonical for every page (page 1 URL); `self` sets each page's own URL as canonical |
| `appendPageToTitle` | `bool` | `false` | Appends "— Page N" to `<title>`, `og:title`, and `twitter:title` on pages > 1 |

When `canonicalMode` is `'self'`, hreflang alternate URLs are also rewritten to point at the respective page for each locale.

---

### `craft.beacon.setBreadcrumbs(items)`

Overrides the auto-derived `BreadcrumbList` JSON-LD with a custom chain. Call before `head()`.

```twig
{% do craft.beacon.setBreadcrumbs([
  { name: 'Home',     url: siteUrl() },
  { name: 'Products', url: siteUrl('products') },
  { name: entry.title },
]) %}
```

Each item is `{ name: string, url?: string }`. The `url` is omitted for the final (current) item — schema.org allows it but it is optional.

When you call `setBreadcrumbs()`, the Structure-parent auto-detection is skipped for this request. When you don't call it, Beacon walks the entry's Structure ancestors automatically.

---

## Inspection functions

### `craft.beacon.meta()` → `SeoMeta`

Returns the fully resolved `SeoMeta` object for the current request. Useful for reading values that Beacon computed (e.g. reading the resolved title or canonical URL for use elsewhere in the template).

```twig
{% set m = craft.beacon.meta() %}
<p>Resolved title: {{ m.title }}</p>
<p>Canonical: {{ m.canonical }}</p>
```

Calling `meta()` after `set()` reflects your overrides. Calling `meta()` is equivalent to `getMeta()` — both are aliases.

#### SeoMeta properties

| Property | Type | Description |
|---|---|---|
| `title` | `string` | Resolved page title (already formatted with site name separator) |
| `description` | `string` | Resolved meta description |
| `canonical` | `string\|null` | Canonical URL, or null when not set |
| `robots` | `list<string>` | Robots directives, e.g. `['noindex', 'nofollow']`. Empty = indexable |
| `openGraph` | `array` | Open Graph data: `title`, `description`, `type`, `url`, `image`, `imageWidth`, `imageHeight`, `imageAlt`, `siteName`, `locale` |
| `twitter` | `array` | Twitter Card data: `card`, `title`, `description`, `image`, `site`, `creator` |
| `articleTimes` | `array\|null` | `publishedTime` / `modifiedTime` as ISO-8601 strings. Set when `og:type=article` |
| `alternates` | `list<{hreflang, href}>` | Hreflang alternate pairs |
| `paginationLinkTags` | `list<{rel, href}>` | `rel=prev` / `rel=next` pairs, set by `setPagination()` |
| `sourceMap` | `array<string,string>` | Debug map of which field/layer supplied each resolved value |

All `set()` overrides write to these properties directly, so the same key names are valid in `set()` calls.

---

### `craft.beacon.tags()` → `array`

Returns the resolved name-keyed meta tag map as an associative array of `{attr, name, content}` rows. Reflects all `setTag()` / `removeTag()` overrides. Useful for debugging or for emitting tags manually.

```twig
{% for name, tag in craft.beacon.tags() %}
  {# tag.attr is 'name' or 'property' #}
  <meta {{ tag.attr }}="{{ tag.name }}" content="{{ tag.content }}">
{% endfor %}
```

---

### `craft.beacon.schemas()` → `array`

Returns the resolved JSON-LD schema array (all nodes that `head()` would emit). Useful for inspecting the graph or for forwarding to a headless consumer.

```twig
{% set graph = craft.beacon.schemas() %}
{# graph is a list of schema.org node objects #}
{{ graph|json_encode }}
```

---

### `craft.beacon.breadcrumbs()` → `array`

Returns the resolved breadcrumb chain as a list of `{name: string, url: string|null}` items, after any `setBreadcrumbs()` override. Does **not** emit any HTML or JSON-LD — use `head()` for that.

```twig
<nav aria-label="Breadcrumb">
  <ol>
    {% for crumb in craft.beacon.breadcrumbs() %}
      <li>
        {% if crumb.url and not loop.last %}
          <a href="{{ crumb.url }}">{{ crumb.name }}</a>
        {% else %}
          {{ crumb.name }}
        {% endif %}
      </li>
    {% endfor %}
  </ol>
</nav>
```

---

### `craft.beacon.debug()` → `array`

Returns a diagnostic snapshot of the current meta resolution state. Intended for development — dump it in a template comment or a debug toolbar.

```twig
{# Only expose in devMode #}
{% if craft.app.config.general.devMode %}
  <!-- beacon:debug {{ craft.beacon.debug()|json_encode }} -->
{% endif %}
```

Keys returned:

| Key | Description |
|---|---|
| `route` | The matched element URL, or request path for non-element pages |
| `tagCount` | Number of `<meta>` tags that would be emitted |
| `schemaCount` | Number of JSON-LD nodes in the graph |
| `metaCache` | `'request-cache-hit'`, `'cross-request-hit'`, `'cross-request-miss'`, or `'disabled'` |
| `schemaCache` | `'hit'` or `'miss'` |
| `sourceMap` | Per-field origin map (which layer supplied title, description, image, etc.) |
| `robots` | The resolved robots directive array |
| `tags` | The full tag map (same as `tags()`) |

---

## Social profiles

### `craft.beacon.socials()` → `array`

Returns all configured social profile URLs from **Settings → Identity → Social profiles** as a list. Entries are only included when the URL is set and non-empty.

```twig
{% set profiles = craft.beacon.socials() %}
{% for profile in profiles %}
  <a href="{{ profile.url }}" rel="me noopener" aria-label="{{ profile.label }}">
    {{ profile.platform }}
  </a>
{% endfor %}
```

Each item:

| Key | Type | Description |
|---|---|---|
| `platform` | `string` | Platform key, e.g. `twitter`, `github`, `linkedin`, `mastodon` |
| `url` | `string` | Full profile URL |
| `handle` | `string\|null` | Parsed handle (e.g. `@username` for Twitter; null for platforms where handles can't be extracted) |
| `label` | `string` | Human-readable platform label |

---

### `craft.beacon.socialUrl(platform)` → `string|null`

Returns the URL for a single platform, or `null` when it isn't configured.

```twig
{% set twitter = craft.beacon.socialUrl('twitter') %}
{% if twitter %}
  <a href="{{ twitter }}">Follow us on Twitter</a>
{% endif %}
```

Platform keys: `twitter`, `facebook`, `linkedin`, `instagram`, `youtube`, `github`, `mastodon`, `threads`, `bluesky`, `tiktok`, `pinterest`, `xing`, `vimeo`.

---

## Authors

### `craft.beacon.authors()` → `AuthorQuery`

Returns an `AuthorQuery` — the entry point for fetching [Beacon Author elements](AUTHORS.md). Chainable with standard Craft element query methods.

```twig
{# All enabled authors #}
{% set authors = craft.beacon.authors().all() %}

{# Authors by IDs from an SEO field #}
{% set entry = craft.entries.one() %}
{% set authors = craft.beacon.authors().id(entry.beaconSeo.authorIds).all() %}

{# Single author by slug #}
{% set author = craft.beacon.authors().slug('jane-doe').one() %}
```

The `AuthorQuery` supports all standard Craft query modifiers: `.id()`, `.slug()`, `.title()`, `.siteId()`, `.status()`, `.orderBy()`, `.limit()`, `.offset()`, `.all()`, `.one()`, `.count()`.

Returning all authors in a list:

```twig
<ul>
  {% for author in craft.beacon.authors().orderBy('title ASC').all() %}
    <li>
      <a href="{{ author.getUrl() }}">{{ author.title }}</a>
      {% if author.jobTitle %} — {{ author.jobTitle }}{% endif %}
    </li>
  {% endfor %}
</ul>
```

---

## Call order reference

The order of calls matters. Overrides must precede `head()`:

```twig
{# 1. Pagination — call first, clears meta cache #}
{% do craft.beacon.setPagination({...}) %}

{# 2. Field overrides #}
{% do craft.beacon.set('title', customTitle) %}
{% do craft.beacon.set('robots', ['noindex']) %}

{# 3. Tag overrides #}
{% do craft.beacon.setTag('theme-color', '#fff') %}
{% do craft.beacon.removeTag('twitter:image') %}

{# 4. Breadcrumb override #}
{% do craft.beacon.setBreadcrumbs([...]) %}

{# 5. Additional schema nodes #}
{% do craft.beacon.addSchema({...}) %}

{# 6. head() — locks and emits everything #}
{{ craft.beacon.head() }}
```

Calling any `set*()` / `add*()` after `head()` has no effect on the current request.

---

## Headless / API usage

When serving a headless front-end, use `meta()`, `tags()`, `schemas()`, and `breadcrumbs()` to forward resolved data via a JSON API or GraphQL resolver without emitting any HTML.

```twig
{# REST endpoint template — returns JSON #}
{% header 'Content-Type: application/json' %}
{{ {
  meta: {
    title: craft.beacon.meta().title,
    description: craft.beacon.meta().description,
    canonical: craft.beacon.meta().canonical,
    robots: craft.beacon.meta().robots,
  },
  schemas: craft.beacon.schemas(),
  breadcrumbs: craft.beacon.breadcrumbs(),
}|json_encode }}
```

---

## Template override

Beacon's own public templates (e.g. the author profile page) follow standard Craft plugin-template override: place your version in `templates/beacon/public/author-profile.twig` inside the project `templates/` folder and it takes precedence.
