# Changelog — Beacon

## 1.2.0 — 2026-06-29

### Added
- **Internal links** feature: TF-IDF keyword index, optional AI-embedding suggestions, CP reports (overview, orphans, link map, link detail, click depth, broken links, anchor text, external links), entry-editor sidebar, dashboard widget, `craft.beacon.links.*` Twig API, and console commands (`beacon/link-index`, `link-snapshot`, `link-report`, `link-audit`).
- Master **Enable internal links** toggle on Links → Settings — disables indexing, sidebar, reports, Twig helpers, and console (except `link-index/clear`).
- Per-suggestion **Highlight** buttons in the entry sidebar, mutually exclusive with highlight-all.

### Fixed
- SSRF guard for IPv6-only hosts in broken-link audit (#9).
- Protocol-relative URL classification in link parser (#10).
- AI content controller error handling (#11).
- Rate limiter TTL reset for active editors (#12).
- robots.txt cache invalidation after AI-usage policy changes (#13).
- Citation/competitor substring false positives (#14).
- Avg-links KPI denominator consistency (#15).
- URI resolution when site URL substring repeats (#16).
- Shared index pipeline via `IndexesEntries` trait (#17).
- Atomic `Db::upsert` in suggestion `recordInteraction()` (#18).

## 1.1.0 — 2026-06-24

### Added
- GEO/LLM feature set: AI-assisted content generation, AI-visibility citation tracking across benchmark prompts, licensing/usage signals, token-aware `llms-full.txt`, and entity linking.
- Read/write MCP tool surface (`beacon.*`) for redirects, 404s, llms.txt, entry SEO/meta, and GEO scores, gated by Beacon permissions.

### Security
- MCP entry read tools (`get_entry_seo`, `get_geo_score`) now enforce element-level `canView` authorization in addition to the dashboard permission.
- `set_entry_meta` requires an authenticated token user with `canSave` on the target entry and declares an explicit edit permission.
- AI-visibility benchmark prompts are now site-scoped on save and delete, preventing cross-site edits/deletions.

### Changed
- Capped AI-visibility probes per run at 100 to stay within queue-job time limits.

## 1.0.0 — 2026-06-09
