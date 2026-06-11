# GEO (Generative Engine Optimization) — operational playbook

Beacon's GEO surfaces give LLMs and AI crawlers structured, attribution-friendly access to your content. This playbook covers turning them on, what each surface does, how to tune them for your editorial policy, and the operational caveats.

## What "GEO" means in Beacon

Three public endpoints + one per-entry export:

| Surface | URL | Toggle |
|---|---|---|
| `llms.txt` | `GET /llms.txt` | per-site, CP at `/admin/beacon/crawlers/llms-txt` |
| `humans.txt` | `GET /humans.txt` | per-site, CP at `/admin/beacon/crawlers/humans-txt` |
| `ads.txt` | `GET /ads.txt` | per-site, CP at `/admin/beacon/crawlers/ads-txt` |
| GEO Markdown export | `GET /geo/export?id=<entryId>` or `GET /<entry-uri>.md` | global, Settings → GEO Markdown |
| AI crawler rules | merged into `robots.txt` | `/admin/beacon/crawlers/ai-crawlers` |

The `.md` suffix route is opt-in via `geoMarkdownMdSuffixEnabled`. Without it, only the `?id=` form responds.

## Phase 1 — Turn on llms.txt

`llms.txt` is the [llms.txt convention](https://llmstxt.org/) used by AI assistants to learn what your site is about, which sections matter, and how to attribute it.

1. Visit `/admin/beacon/crawlers/llms-txt` per site.
2. Enable the site.
3. Set a one-line **summary** (purpose, audience, what to look at).
4. Pick the **sections** to include — Beacon serves the latest live entries from each as a Markdown list.
5. (Recommended) Set the **trust block**:
   - **Site policy URL** (`policyUrl`) — link to your AI use policy
   - **License URL** (`licenseUrl`) — content license, e.g. CC-BY-SA
   - **Contact email** (`contactEmail`) — single point of contact for AI vendors
   - **Preferred attribution** — short string like "Cite as 'Acme Docs (acme.com)'"

The rendered file at `/llms.txt` looks roughly:

```
# Acme Documentation

> Reference manuals and how-tos for Acme platform integrators.

## docs

- [Quickstart](https://acme.com/docs/quickstart): 5-minute getting-started guide
- [API reference](https://acme.com/docs/api): Endpoint catalog

## Trust

- Site policy URL: <https://acme.com/ai-policy>
- License URL: <https://creativecommons.org/licenses/by-sa/4.0/>
- Contact: <ai@acme.com>
- Preferred attribution: <Cite as 'Acme Docs (acme.com)'>
```

### Caveats

- CRLF in any field is stripped — you cannot inject multi-line content from a single setting field.
- The list is **derived from live entries**. Drafts and disabled sections are excluded.
- No pagination — large sites should pick sections deliberately, not "all of them."
- Updates are cached via `RenderCacheService` under `RenderCacheType::LlmsTxt` and invalidated on element save in listed sections + site config changes.

## Phase 2 — AI crawler controls

Beacon distinguishes **AI training crawlers** from **classic search crawlers** and gives you per-bot rules.

1. Visit `/admin/beacon/crawlers/ai-crawlers`.
2. Use the **Restore defaults** button if you want the curated bot list (GPTBot, ClaudeBot, Google-Extended, CCBot, etc.).
3. For each bot, decide:
   - **Allow** — bot crawls normally
   - **Disallow** — listed in robots.txt with `Disallow: /`
   - **Disallow specific paths** — `Disallow: /private`, `/staging`, etc.
4. Save. Beacon's `/robots.txt` immediately reflects the new rules.

You can also write **custom bot definitions**. Each definition uses a user-agent regex; pathological patterns are rejected at save time and matched under a lowered backtrack limit.

### Operational guidance

| Editorial position | Recommended config |
|---|---|
| "We want to be cited but not trained on" | Allow `OAI-SearchBot`, `Perplexity-User`; Disallow `GPTBot`, `ClaudeBot`, `CCBot`, `Google-Extended` |
| "Open content, training OK" | Restore defaults, then Allow everything |
| "Maximum protection" | Disallow all AI crawlers; keep classic crawlers (Googlebot, Bingbot) allowed |
| "Public docs only" | Disallow all AI on `/private/*`; Allow on `/docs/*` via path lists |

### Caveats

- Beacon **does not enforce** the rules. `robots.txt` is voluntary. Compliance is bot-dependent.
- The [`User-Agent: *`](https://www.rfc-editor.org/rfc/rfc9309) rule applies to all bots; AI-specific lines are layered on top.
- Bot logging (Settings → Behavior → "Log AI crawler hits") records every request from a matched user agent — retention defaults to 30 days, configurable.

## Phase 3 — Per-URL Markdown (`/geo/export` + `.md` suffix)

The most LLM-friendly response is **stripped Markdown** of the content. Beacon serves this in two ways:

### `?id=` route

`GET /geo/export?id=123` returns Markdown for entry 123 if `geoMarkdownEnabled = true` and the entry's section is in `geoMarkdownSectionAllowlist` (empty list = all sections allowed).

Throttled at 60 req/min keyed on `(user, IP)` to avoid scrape farms.

### `.md` suffix route

If `geoMarkdownMdSuffixEnabled = true`, Beacon also registers `GET /<entry-uri>.md`. Requests for `https://acme.com/blog/post-1.md` return the Markdown for the entry at `/blog/post-1`.

URI collision rule: if an entry's *literal* URI ends in `.md`, Craft's element resolution wins and the `.md` route is never reached. This is by design — Beacon doesn't shadow real entries.

### Accept-header negotiation

Set `geoMarkdownNegotiateAcceptHeader = true` to opt into content negotiation. Requests with `Accept: text/markdown` to any entry URL get Markdown back instead of the HTML view. Disabled by default — turning it on changes responses for *all* entries in allowlisted sections.

### Auto-serve bots

`geoMarkdownAutoServeBots = true` serves Markdown automatically when the request's `User-Agent` matches a known AI bot. Useful if you want crawlers to ingest the stripped form without explicit negotiation.

### Output shape

Two render strategies, controlled by `geoMarkdownFullPageRender`:

- **`true` (default):** Renders the entry's Twig template, strips classes listed in `geoMarkdownExcludedClasses` (e.g. `nav`, `footer`, `cookie-banner`), and HTML→Markdown converts the result. Closest to the canonical reading view.
- **`false`:** Pulls Markdown from a single field handle (`geoMarkdownBodyFieldHandle`, default `body`; configure via `config/beacon.php`). Faster, no DOM walk, but loses anything outside that field.

Optional `geoMarkdownExcerptLength` truncates the body (useful for "summary-only" exports). Optional `geoMarkdownExcerptFallbackToDescription` falls back to the SEO description if the body field is empty.

### Scoping what gets exported (full-page-render mode)

When `geoMarkdownFullPageRender = true` you have three increasingly precise ways to scope content. They compose — start with whichever matches your template structure:

1. **`geoMarkdownExcludedClasses` setting** — global list of CSS classes whose elements get stripped before HTML→Markdown conversion. Use for site-wide chrome (cookie banners, "related posts" boxes). Set in Settings → GEO Markdown.

2. **`{% beaconmdignore %}…{% endbeaconmdignore %}` Twig tag** — wrap any template region that should be dropped from the Markdown export but still render in the HTML page. Common targets: site nav, footer, ad units, share buttons. The tag emits invisible HTML comments at render time — visitors see nothing.

   ```twig
   {% beaconmdignore %}
     <nav class="related-posts">{# … #}</nav>
   {% endbeaconmdignore %}
   ```

3. **`{% beaconmd %}…{% endbeaconmd %}` Twig tag** — when *any* `beaconmd` block exists on the page, only the content inside those blocks is exported. Use this for hand-crafted, AI-optimised summaries that differ from the visible body.

   ```twig
   {% beaconmd %}
     # {{ entry.title }}
     {{ entry.summary }}
     {{ entry.body|markdown }}
   {% endbeaconmd %}
   ```

   Combine with `beaconmdignore` inside the keep block if you want most of a region but with a few exclusions.

If you prefer raw HTML comments over Twig tags (e.g. in a template you don't own), the underlying marker pairs work identically:
`<!--beacon:md-keep-start-->` … `<!--beacon:md-keep-end-->` and `<!--beacon:md-drop-start-->` … `<!--beacon:md-drop-end-->`.

Beacon also drops the standard HTML5 chrome elements (`<head>`, `<header>`, `<nav>`, `<footer>`, `<aside>`) and Yii view-layer placeholders unconditionally — you don't need to mark these.

### Front matter

Beacon prepends YAML front matter to every exported Markdown:

```yaml
---
title: Quickstart
url: https://acme.com/docs/quickstart
section: docs
publishDate: 2026-04-12
license: CC-BY-SA-4.0
---
```

Three layers, deepest wins on key collision:

1. **Site default** (`geoMarkdownFrontMatterDefaults`) — global keys like license / publisher
2. **Section default** (per section, in sitemap settings)
3. **Entry-level** — derived from entry fields (title, URL, dates)

## Phase 4 — Pre-generation

For high-traffic exports, Beacon caches generated Markdown in `{{%beacon_geo_markdown}}`. Two paths:

- **Lazy:** First request generates + writes through. Subsequent reads hit the cache. Element save invalidates.
- **Eager:** Run `php craft beacon/cache/regenerate-all` after a deploy. Warms llms.txt + sitemaps + the Markdown table.

If `geoMarkdownFullPageRender = true`, generation does a full Twig render. Plan budgets accordingly — eager warm of a 10k-entry site takes minutes.

## Phase 5 — Editorial governance

Recommended cadence:

| Frequency | Check |
|---|---|
| Weekly | Review `/admin/beacon/redirects` for stale rules + 404 candidates |
| Weekly | Spot-check `/llms.txt` and three random `.md` exports |
| Monthly | Re-read your AI use policy linked from the trust block |
| Quarterly | Re-evaluate AI crawler ruleset — new bots ship constantly |
| On content takedown | Element save invalidates Markdown — verify the `.md` is gone or rewritten |

## Compliance and privacy

- **Markdown export of noindex entries returns 404.** Beacon deliberately doesn't 403 because that leaks existence. Authors who toggle `noIndex` on the SEO field also disable the `.md` export.
- **Throttling key includes user ID** (or `'anon'` for unauthenticated requests). Header rotation alone doesn't bypass the throttle.
- **CRLF stripping** is applied at every interpolation site in `llms.txt`, `humans.txt`, and `robots.txt`.
- **Schema.org `licenseUrl`** is shipped in trust block AND in JSON-LD Organization output. Keep them in sync.

## Public surface diagnostics

```bash
# llms.txt
curl -i https://example.com/llms.txt

# humans.txt
curl -i https://example.com/humans.txt

# ads.txt
curl -i https://example.com/ads.txt

# Markdown for an entry
curl -i 'https://example.com/geo/export?id=42'

# .md suffix (requires geoMarkdownMdSuffixEnabled=true)
curl -i https://example.com/docs/quickstart.md

# Accept-header negotiation (requires geoMarkdownNegotiateAcceptHeader=true)
curl -i -H 'Accept: text/markdown' https://example.com/docs/quickstart

# Robots with AI rules
curl -i https://example.com/robots.txt
```

Each surface logs to its own cache type. Hit the `php craft beacon/cache/regenerate-all` command to invalidate after schema changes.

## See also

- [llms.txt configuration](LLMS_TXT.md)
- [Settings reference](SETTINGS.md)
- [Extensibility cookbook](EXTENSIBILITY_COOKBOOK.md) — for custom Markdown render pipelines
