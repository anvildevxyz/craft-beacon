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

## Track 2 — GEO / AI visibility (extend the lead)

- [ ] **AI-visibility tracking panel** — periodically send benchmark prompts to ChatGPT, Perplexity, Claude, and Gemini APIs; record whether the site is cited (URL match + domain match); chart citation rate over time in the Dashboard. Credentials stored in plugin settings per provider.
- [ ] **Content-licensing signals** — add `LLM-Content-Policy` header and `X-Robots-Tag` variant support for Cloudflare's Content Signals spec; surface a per-section "AI usage license" dropdown (index / no-train / no-generative-ai) that writes the appropriate signals to the sitemap and robots.txt.
- [ ] **MCP server** — expose entries, meta fields, GEO scores, redirects, and sitemap state as MCP tools/resources so AI coding agents and editorial agents can read and update SEO data programmatically. Served from `/beacon/mcp`.

---

## Track 3 — Redirects polish (finish the feature)

- [ ] **Scheduled / expiring redirects** — `active_from` and `active_until` datetime fields per redirect; a queue job (`ProcessRedirectExpiryJob`) activates/deactivates on schedule; useful for campaign landing pages and site migrations.
- [ ] **Regex test sandbox** — CP panel where an author types a URL and sees in real time which redirect rule (if any) would match it, what the resolved destination is, and the match rank; prevents misconfigured regex from going live.
- [ ] **Domain-migration import mode** — accept a plain text file of old URLs (one per line) and a new base URL; auto-create 301 redirects mapping each old path to the equivalent new path; useful for full domain switches.

---