# Beacon — Post-v1 Roadmap

Items are ordered by implementation priority within each track. Check off each item as it ships.

---

## Track 1 — Classic SEO (close the "is this a real SEO plugin?" objection)

- [ ] **Content analysis at edit time** — focus keyphrase field, title/description character-count with traffic-light feedback, readability score (Flesch or Flesch–Kincaid), keyphrase density indicator, and a per-entry SEO score shown in the element editor sidebar.
- [ ] **Schema validator UI** — after an editor configures a JSON-LD type, run a validation pass that flags missing required properties (e.g. `Product` without `offers`) and warns on recommended-but-absent properties, surfacing which rich-result type is at risk.
- [ ] **AI-assisted meta generation** — "Generate" button on title/description fields that calls an LLM (model configurable in settings) with the entry's content as context; streamed into the field so editors can accept or tweak.
- [ ] **Google Search Console integration** — OAuth 2 connect flow in plugin settings; per-entry impressions, clicks, CTR, and average position surfaced in the element editor sidebar; index-coverage status badge; GSC data reflected in Dashboard widgets.
- [ ] **Image SEO audit** — scan section entries for missing or generic alt text; report in Dashboard; flag in per-entry sidebar.
- [ ] **Image sitemap and video sitemap** — `sitemap-images.xml` and `sitemap-videos.xml` endpoints (in addition to the existing news sitemap), respecting the same per-section on/off toggles as the main sitemap.
- [ ] **Dynamic OG image generation** — generate `og:image` on-demand from a configurable template (entry title + site logo overlay at minimum); serve via `/beacon/og/<uid>` with HTTP caching headers; path configurable to point to a custom Twig template.
- [ ] **Site-wide SEO audit** — background crawl of all public URLs to surface: duplicate `<title>` / `<meta description>`, missing meta, 0-inbound-link orphan pages, broken internal links, redirect chains longer than 2 hops. Results visible in a new Audit CP section with filters and bulk-fix actions.

---

## Track 2 — Ecosystem adoption (remove the switching barrier)

- [ ] **SEOmatic field-data importer** — detect SEOmatic's `SeoSettings` custom field on elements and copy title/description/OG overrides into the Beacon SEO field in a one-time migration command (`php craft beacon/migrate/seomatic`).
- [ ] **Retour redirects importer** — read from Retour's `retour_redirects` DB table and bulk-insert into Beacon's redirect table via a migration command (`php craft beacon/migrate/retour`).
- [ ] **Redirect importer: Retour CSV + SEOmatic CSV formats** — auto-detect column schema in the CSV importer so exports from Retour and SEOmatic import without column remapping.
- [ ] **Onboarding wizard** — first-run modal that detects existing sections, proposes sitemap inclusion defaults, sets the organization name/logo from Site Settings if available, and links to quick-start docs.
- [ ] **Bulk-edit table** — a CP section listing all entries across a chosen section with inline-editable SEO title, meta description, and canonical override columns; save all changes in one POST.

---

## Track 3 — GEO / AI visibility (extend the lead)

- [ ] **AI-visibility tracking panel** — periodically send benchmark prompts to ChatGPT, Perplexity, Claude, and Gemini APIs; record whether the site is cited (URL match + domain match); chart citation rate over time in the Dashboard. Credentials stored in plugin settings per provider.
- [ ] **Content-licensing signals** — add `LLM-Content-Policy` header and `X-Robots-Tag` variant support for Cloudflare's Content Signals spec; surface a per-section "AI usage license" dropdown (index / no-train / no-generative-ai) that writes the appropriate signals to the sitemap and robots.txt.
- [ ] **MCP server** — expose entries, meta fields, GEO scores, redirects, and sitemap state as MCP tools/resources so AI coding agents and editorial agents can read and update SEO data programmatically. Served from `/beacon/mcp`.

---

## Track 4 — Redirects polish (finish the feature)

- [ ] **Scheduled / expiring redirects** — `active_from` and `active_until` datetime fields per redirect; a queue job (`ProcessRedirectExpiryJob`) activates/deactivates on schedule; useful for campaign landing pages and site migrations.
- [ ] **Regex test sandbox** — CP panel where an author types a URL and sees in real time which redirect rule (if any) would match it, what the resolved destination is, and the match rank; prevents misconfigured regex from going live.
- [ ] **Domain-migration import mode** — accept a plain text file of old URLs (one per line) and a new base URL; auto-create 301 redirects mapping each old path to the equivalent new path; useful for full domain switches.

---

## Track 5 — Developer experience

- [x] **Twig API reference page** — `docs/TWIG_API.md` written; all `craft.beacon.*` functions documented with parameters, return types, examples, call-order rules, and headless usage.
- [x] **Permissions reference** — `docs/PERMISSIONS.md` written; all 10 CP user permissions + 4 GraphQL schema components documented with handles, labels, what each gates, recommended group configs, and PHP/Twig usage examples.

---

## Completed ✓

- [x] llms.txt with trust block
- [x] AI-crawler controls + bot-activity log
- [x] GEO Markdown export with six-pillar content scoring
- [x] IndexNow ping (service + job + key management)
- [x] Full schema.org catalogue (hundreds of types)
- [x] News sitemap
- [x] 404 suggestion engine with cosine-similar target ranking
- [x] Redirect chain and loop detection
- [x] Tracking-script manager with per-environment support
- [x] Short links
- [x] GraphQL `beacon` field with full type coverage
- [x] PHPBench-enforced CI budgets
- [x] Debug meta source-map resolver
- [x] hreflang, breadcrumbs, pagination, feeds, schemamap, ads.txt, humans.txt
