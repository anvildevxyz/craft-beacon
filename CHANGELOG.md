# Changelog — Beacon

## 1.3.0 — 2026-07-26

> Reconciles the two release lines. The 1.2.x releases were tagged off the
> internal-links branch while `main` carried the GEO/LLM work; both are now on
> `main`. Sites on 1.1.x gain the internal-links feature, sites on 1.2.x gain
> the GEO/LLM feature set, and both gain the performance work below.

### Added
- **Internal links** and the **GEO/LLM feature set** are now available in a single release — see the 1.2.0 and 1.1.0 entries for their details.

### Changed
- Beacon no longer queries on every front-end request: the settings, schema, AI-bot and site-settings rows it reads on each page are cached across requests and dropped by tag when the underlying record is written.
- `beacon_render_cache.contentKey` is now `NOT NULL` (empty string for a type's master document), which lets writes become a single upsert and closes a duplicate-row window — both databases treated `NULL` keys as distinct in the unique index, so master rows were never covered by it.
- GEO rescoring targets are coalesced per request and processed in batches of 100 rather than one queue job per element.
- 404s no longer pay for a short-link lookup on sites that have no short links.

### Fixed
- The AI provider key is no longer written to the on-disk cache in the clear; it is encrypted with the app security key.
- Cache-tag invalidation now also fires after the element-save transaction commits, so a concurrent request can no longer re-cache a pre-write value against the bumped tag — which could leave short links unresolvable or settings stale indefinitely.
- A value built while a write lands mid-rebuild is no longer stored as fresh.
- Cached record payloads carry a 5-minute ceiling, bounding divergence on multi-server deployments where tag invalidation only reaches one node.
- Buffered GEO rescoring targets survive a request that dies before its after-request event, and drain after every queue job rather than waiting for a long-lived worker to exit.
- A failing element in a batched GEO rescore no longer abandons the rest of the batch.
- The Sitemap Health widget labels an unchunked site's sitemap `(single)` again instead of rendering a blank cell.

## 1.2.1 — 2026-06-29

### Fixed
- PHPStan: use `Http::request()` for JSON detection in `RequiresLinksEnabledTrait`.
- PHPStan: add `Action<Controller>` generic to `LinksController::beforeAction()`.

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
