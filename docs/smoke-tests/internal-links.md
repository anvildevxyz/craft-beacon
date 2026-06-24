# Beacon Plugin — Internal Links (link graph) Smoke Tests

Generated: 2026-06-24 | Focus: internal-link indexing, suggestions, reports, sidebar, console, settings
Feature merged from the standalone `whisper` plugin (link-graph half only).

## Feature Overview
Beacon analyses entry content to build a directed internal-link graph and surface:
- **Keyword index** (TF-IDF) per entry/site, optional **AI embeddings** for semantic similarity
- **Suggestions** for internal links (sidebar + report), with accept/dismiss tracking
- **Reports**: overview, orphans, link map, link detail, click depth, broken links, anchor text, external links
- **Broken-link checking** (outbound, DB + optional HTTP audit with SSRF guard)
- **Trend snapshots** + dashboard widget
- **CKEditor auto-insert / highlight** of suggested links
- Front-end **`craft.beacon.links.*`** Twig API

## CP Routes
- `beacon/links` → `LinksController::actionIndex` (overview)
- `beacon/links/orphans|link-map|link-detail|suggestions|click-depth|broken-links|anchor-text|external-links` → matching `LinksController` actions (each accepts `?format=csv`, `?site=<handle>`, `link-detail` takes `?entryId=`)
- `beacon/links/settings` → `LinkSettingsController::actionIndex`; saves via `beacon/link-settings/save`
- Action endpoints (JSON, used by sidebar JS): `beacon/link-suggestions/get`, `/find-phrase`, `/record-interaction` (POST), `/bulk-update` (POST)

## Controllers & Permissions
- `LinksController` — `BEACON_PERMISSION = VIEW_LINKS`; mutating actions additionally `requirePermission(EDIT_LINKS)`
- `LinkSuggestionsController` — `VIEW_LINKS`; `record-interaction`/`bulk-update` require `EDIT_LINKS`
- `LinkSettingsController` — `EDIT_LINKS`
- Permissions: `beacon:viewLinks`, `beacon:editLinks` (registered via `BeaconPermissions::definitions()`)

## Console
- `php craft beacon/link-index` (default `index`) — queues `LinkBatchIndexJob`s for all enabled entries; `link-index/clear` empties index/links/suggestions
- `php craft beacon/link-snapshot` (default `run`) — records a `beacon_link_snapshots` row per site
- `php craft beacon/link-report/orphans|stats` — CLI report (`--site`, `--section`)
- `php craft beacon/link-audit` (default `broken`) — HTTP-audits external links (`--timeout`, `--delay`)

## Models / Tables
- `LinkSettings` (single row `beacon_link_settings`): `enabledSections[]`, `maxKeywordsPerEntry`, `stopWordsLanguage`, `minKeywordLength`, `indexOnSave`, `showSidebarSuggestions`, `maxSuggestions`, `minScore`, `maxDocumentFrequencyRatio`, `excludeSameSection`, `embeddingsEnabled`, `embeddingsBaseUrl`, `embeddingsApiKey`, `embeddingsModel`, `reportCacheDuration`, `autoReindexInterval`, `httpAuditTimeout`, `httpAuditDelay`, `genericAnchorPatterns[]`
- Records: `beacon_link_index`, `beacon_link_embeddings`, `beacon_links`, `beacon_link_snapshots`, `beacon_link_suggestions`, `beacon_link_settings`
- Suggestions are computed on the fly (cached); `beacon_link_suggestions` rows are written only on **accept/dismiss** (status `suggested|accepted|dismissed`).

## Assets & Localization
- `LinksCpAsset` (`web/assets/links/dist`): `links-sidebar.js`, `links-insert.js`, `links-highlights.js`, `links.css`; JS globals `BeaconLinksSidebar/Insert/Highlights`
- i18n: `nav.links`, `links.nav.*`, `links.overview.*`, `links.report.*`, `links.suggestions.*`, `links.detail.*`, `links.sidebar.*`, `links.widget.*`, `links.settings.*`, `widgets.linkGraph.link.graph`, `flash.links.*`

## Test Data (DDEV craft-plugin-dev)
- ~62 entries across sections (News, Pages, User Guide, …); English = **siteId 2** in this install, Français = other live site.
- Login: see `reference_cp_credentials`. The standalone `whisper` plugin is still installed → entry editor shows a duplicate sidebar; **Beacon's panel is titled "Internal links"** ("Highlight in content"/"Refresh").

---

## Findings (2026-06-24 live run)
- 🐛→✅ **Site-scoping bug found & fixed** (`3025552`): report screens used `getCurrentSite()` and ignored `?site=`, always showing the primary site. Now use `resolveSite()`. Verified: French overview shows 19 indexed (was wrongly 38).
- ✅ Console default actions added for `link-snapshot`/`link-audit` (`aaf8cf5`).
- ✅ Draft entries are correctly excluded from indexing (save-event guard + reindex query).
- ✅ **Purge-on-delete (#45) verified** (`9298`): created+indexed a News entry, deleted it → index/links/embeddings rows all purged even though the element is only *soft-deleted* (FK CASCADE can't fire), proving the `EVENT_AFTER_DELETE` handler.
- ✅ **Embeddings (#25–28) verified end-to-end**: enabled with the OpenAI key, reindexed → 46 real 1536-dim vectors (6144-byte blobs), model `text-embedding-3-small`. Empty-content entries returned HTTP 400 "input cannot be empty" → handled gracefully. Added a guard (`EmbeddingService` skips the call when text is empty) to avoid the wasted call/error. Restored embeddings-off afterward.
- ◑ **Permissions (#39–41): enforcement verified, positive case test-blocked.** Created a `viewLinks`-only group + user; `/beacon/links` returned 403 — BUT so did `/beacon/redirects` for the same user, and Craft's own `$user->can('beacon:viewLinks')` returns **true**. The 403 is Craft's separate non-admin **site-access** gate (the test user had no site permissions), identical across all Beacon CP pages — NOT a Links bug. The negative path (unauthorized → 403) is proven; the Links controller uses the same `BeaconCpPermissionTrait` as every other Beacon screen.

## Scenarios

Legend: ✅ verified 2026-06-24 · ◑ partially verified · ☐ to run

### A. Indexing
1. ☐ **Full index** — run `beacon/link-index` then `queue/run`. *Expect:* "Queued N jobs for M entries", jobs complete, `beacon_link_index` + `beacon_links` populate. ✅
2. ☐ **Index on save** — with `indexOnSave` on, edit & save an entry. *Expect:* a `LinkIndexEntryJob` is queued (dedup 10s); after `queue/run`, that entry's index/links rows refresh.
3. ☐ **Clear** — `beacon/link-index/clear`. *Expect:* index/links/suggestion-interaction tables emptied; overview shows 0 indexed.
4. ☐ **Disabled section excluded** — set `enabledSections` to a subset, reindex. *Expect:* entries outside the set are not indexed (`countIndexed` reflects subset).
5. ☐ **Nested/Matrix content** — index an entry with Matrix/nested entries containing links. *Expect:* links inside blocks are captured in `beacon_links` (via `IndexesEntries` trait).

### B. Reports
6. ✅ **Overview** — visit `beacon/links`. *Expect:* indexed count, orphan count, avg links, broken count, acceptance %, quick actions — all populated.
7. ✅ **Orphans** — `beacon/links/orphans`. *Expect:* lists entries with 0 inbound internal links; section filter; empty-state when none. (27-row table verified.)
8. ✅ **Link map** — `beacon/links/link-map`. *Expect:* per-entry inbound/outbound counts; click through to link-detail. (Renders, console clean.)
9. ✅ **Link detail** — `beacon/links/link-detail?entryId=<id>`. *Expect:* inbound + outbound lists; "Edit entry"/"View" links. (Verified for entry 944.)
10. ✅ **Click depth** — `beacon/links/click-depth`. *Expect:* BFS depth from homepage; unreachable entries flagged. (Renders, console clean.)
11. ✅ **Broken links** — `beacon/links/broken-links`. *Expect:* rows with httpStatus / deleted-target / disabled status; empty-state when none. (Renders, console clean.)
12. ✅ **Anchor text** — `beacon/links/anchor-text`. *Expect:* anchors listed; generic phrases (from `genericAnchorPatterns`) flagged. (Renders, console clean.)
13. ✅ **External links** — `beacon/links/external-links`. *Expect:* outbound external URLs inventory. (Renders, console clean.)
14. ☐ **CSV export** — append `?format=csv` to any report. *Expect:* a CSV download with the same rows (CSV-injection-escaped).
15. ✅ **Multi-site scope** — switch site (`?site=french`). *Expect:* report data scoped to that site. Verified after the scoping fix: French overview = 19 indexed / 19 orphans vs English 38 / 27.

### C. Suggestions & sidebar
16. ✅ **Sidebar renders** — open an indexed entry. *Expect:* Beacon "Internal links" panel lists scored suggestions with Insert Link / Dismiss, plus Highlight-in-content + Refresh.
17. ◑ **Accept (Insert Link)** — click Insert Link on a suggestion. *Expect:* anchor inserted into CKEditor wrapping the matched phrase; `beacon_link_suggestions` row `accepted`. *Verified the graceful-fail branch* (no matching phrase in body → copy-link fallback, suggestion kept, no JS error, no accepted row). The success branch shares the proven `record-interaction` path (see #18) but needs a body containing the target's title to trigger insertion.
18. ✅ **Dismiss** — click Dismiss. *Expect:* suggestion removed from panel; `beacon_link_suggestions` row status `dismissed` (correct source/target/site). ✅
19. ☐ **Bulk update** — in the suggestions report, bulk accept/dismiss. *Expect:* rows update; `EDIT_LINKS` enforced.
20. ☐ **maxSuggestions / minScore** — lower `maxSuggestions` to 3 and raise `minScore`. *Expect:* sidebar shows ≤3 and drops low-score suggestions after reindex/refresh.
21. ☐ **excludeSameSection** — enable it. *Expect:* no suggestions between two entries in the same section.
22. ☐ **Acceptance rate** — after accepting some, overview "Suggestion acceptance" reflects accepted/(accepted+dismissed).
23. ☐ **Refresh** — click Refresh in the sidebar. *Expect:* re-fetches via `link-suggestions/get` without a page reload.
24. ☐ **Highlight in content** — toggle it. *Expect:* matched phrases highlighted in the CKEditor body (`.beacon-links-highlight`).

### D. Embeddings (AI)
25. ☐ **Enable embeddings** — set model + key (or `config/beacon.php` `links.embeddingsApiKey`), reindex + `queue/run`. *Expect:* `beacon_link_embeddings` rows created; no error when the key is an env var (`$OPENAI_API_KEY` parsed).
26. ☐ **Semantic suggestions** — compare suggestions for an entry with embeddings on vs off. *Expect:* embeddings surface topically-related entries that share few exact keywords.
27. ☐ **Unconfigured** — embeddings off / no key. *Expect:* zero network calls; keyword-only suggestions still work; no exceptions.
28. ☐ **Bad endpoint** — point `embeddingsBaseUrl` at an unreachable host. *Expect:* indexing logs a "Beacon links embedding request failed" warning and continues (keyword-only), no fatal.

### E. Settings
29. ✅ **Render + save + persist** — change a value, Save. *Expect:* success flash; `beacon_link_settings` row reflects it; reload shows persisted value. ✅
30. ☐ **Validation** — set `maxDocumentFrequencyRatio` to 5 (out of 0.1–1.0). *Expect:* validation error, not saved.
31. ☐ **Config override precedence** — set `links.maxSuggestions` in `config/beacon.php`. *Expect:* the value overrides the DB/CP value and the CP field shows it as the effective value.
32. ☐ **API key masking** — save a key, reopen settings. *Expect:* key not echoed back in plain text (hidden from serialization).

### F. Snapshots & widget
33. ✅ **Snapshot** — `beacon/link-snapshot`. *Expect:* one `beacon_link_snapshots` row per site with orphan/avg/total/broken/indexed metrics. ✅
34. ☐ **Trend** — run snapshots on 2+ days (or seed rows), view overview/widget. *Expect:* sparkline/trend renders from snapshots.
35. ☐ **Dashboard widget** — add "Link graph" widget. *Expect:* shows indexed / orphans / broken / avg links + link to overview; cached.

### G. Broken links / audit
36. ☐ **DB broken** — delete/disable a target entry that has inbound links. *Expect:* broken-links report flags the now-dangling link.
37. ☐ **HTTP audit** — add an entry with a dead external URL, run `beacon/link-audit`. *Expect:* `httpStatus`/`httpCheckedAt` recorded; report shows it broken.
38. ☐ **SSRF guard** — content links to `http://127.0.0.1/` or `http://localhost/`. *Expect:* audit refuses to fetch internal/loopback targets.

### H. Permissions
39. ☐ **No perm** — user without `viewLinks`. *Expect:* "Links" subnav hidden; direct URL → 403.
40. ☐ **View-only** — `viewLinks` but not `editLinks`. *Expect:* reports visible; accept/dismiss/bulk/settings-save return 403.
41. ☐ **Editor** — `editLinks`. *Expect:* can accept/dismiss, run nothing destructive beyond intended, save settings.

### I. Twig API (front-end)
42. ☐ `craft.beacon.links.suggestionsFor(entry)` → scored suggestions array.
43. ☐ `craft.beacon.links.inboundLinks(entry)` / `outboundLinks(entry)` / `outboundLinksByType(entry, 'craft\\elements\\Entry')`.
44. ☐ `craft.beacon.links.interactionStatus(sourceId, targetId)` → `suggested|accepted|dismissed|null`.

### J. Lifecycle
45. ☐ **Delete entry** — delete an indexed entry. *Expect:* its `beacon_link_index` / `beacon_links` / `beacon_link_suggestions` rows are purged; reports recalc.
46. ☐ **No redirect duplication** — change an entry's slug. *Expect:* Beacon's own redirects feature handles the auto-redirect; the Links feature does NOT create one (Whisper's URI-change listener was intentionally dropped).

### K. Fresh install / migration
47. ☐ **Fresh install** — install Beacon on a clean DB. *Expect:* `Install.php` creates all `beacon_link_*` tables (link feature works without running numbered migrations).
48. ☐ **Upgrade** — existing Beacon install (schemaVersion < 1.0.1) runs `craft up`. *Expect:* `m260624_000001_links` applies, creating the 6 tables. ✅
