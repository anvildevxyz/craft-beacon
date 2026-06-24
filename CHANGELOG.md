# Changelog — Beacon

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
