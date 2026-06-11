# Redirect CSV Import

**CP path:** `/admin/beacon/redirects/import?site=default`  
**Source:** `RedirectImporter::importFromCsv()` + `RedirectsController::actionImport()`

---

## CSV format

```
source,target,statusCode,queryStringMode
/old-path,/new-path,301,ignore
/old-page,https://example.com/page,302,preserve
regex:^/products/(\d+)$,/shop/$1,301,ignore
/section/*,/new-section/*,301,ignore
/multi/**/deep,/new/**,301,ignore
```

The file must be UTF-8 (with or without BOM — the importer strips a leading `\xEF\xBB\xBF` automatically, so Excel/Numbers exports work without preprocessing).

---

## Columns

| Column | Required | Default | Notes |
|---|---|---|---|
| `source` | **yes** | — | Path, glob, or `regex:` pattern (≤ 255 chars) |
| `target` | **yes** | — | Relative path or `http(s)://` URL (≤ 500 chars) |
| `statusCode` | no | `301` | See [Status codes](#status-codes) |
| `queryStringMode` | no | `ignore` | See [Query-string modes](#query-string-modes) |

The header row is **required** and must contain at least `source` and `target` — the other two columns are optional and may be omitted entirely. Column order does not matter; the importer resolves columns by name.

---

## `source` column — redirect type detection

| Pattern | Detected type | Example |
|---|---|---|
| Plain path with no wildcards | **Exact** | `/old-path` |
| Contains `*` or `**` | **Glob** | `/blog/*`, `/docs/**/index` |
| Prefixed with `regex:` | **Regex** | `regex:^/products/(\d+)$` |

**Glob wildcards:**
- `*` — single path segment (no `/`)
- `**` — multiple segments (crosses `/`)

**Regex:**
- Strip the `regex:` prefix before writing the PCRE pattern.
- The pattern is validated with `SafeRegex::validate()` before import; invalid patterns produce a row-level error and the row is skipped.
- Capture groups (`$1`, `$2`, …) may be referenced in `target`.

---

## `target` column — allowed values

| Form | Example | Allowed |
|---|---|---|
| Relative path | `/new/path` | ✓ |
| Absolute `https://` URL | `https://example.com/page` | ✓ |
| Absolute `http://` URL | `http://legacy.example.com/` | ✓ |
| Protocol-relative | `//evil.example` | ✗ (open-redirect risk) |
| `javascript:`, `data:`, `file:`, etc. | — | ✗ |

Targets must not contain line breaks. Max length: **500 characters**.

---

## Status codes

| Value | Meaning |
|---|---|
| `301` | Moved Permanently *(default)* |
| `302` | Found (temporary) |
| `307` | Temporary Redirect (method-preserving) |
| `308` | Permanent Redirect (method-preserving) |

Any other integer is rejected with a row-level error.

---

## Query-string modes

| Value | Behaviour |
|---|---|
| `ignore` | Match on path only; incoming query string is dropped *(default)* |
| `preserve` | Match on path; if the target has no query string, append the incoming one (useful for preserving `?utm_*` tags) |
| `match` | `source` may include `?key=value`; the full URI (path + query, sorted by key) must match exactly |

---

## Validation rules (per row)

Rows that fail any check are **skipped** and reported as errors; the rest are imported atomically in a single transaction.

- `source` must not be empty
- `target` must not be empty
- `source` must be ≤ 255 characters
- `target` must be ≤ 500 characters
- `source` and `target` must not contain line breaks (`\r` or `\n`)
- `statusCode` must be one of `301`, `302`, `307`, `308`
- `queryStringMode` must be `ignore`, `preserve`, or `match`
- `regex:` sources must be valid PCRE
- `target` must be a relative path or an `http(s)://` URL (protocol-relative and other schemes are rejected)

---

## Transaction behaviour

All valid rows are inserted in a **single database transaction**. If the transaction cannot be committed (e.g. a DB constraint violation), the entire import is rolled back. Individual row-level failures (save errors) are logged as errors in the result but do not abort the transaction for the remaining rows.

Imported redirects are assigned contiguous `sortOrder` values starting after the current highest value, so they appear at the bottom of the list without disrupting existing sort order.

All imported rows have `source = 'csv_import'` (the `RedirectSource::CsvImport` enum value) which is visible in the CP filter and can be used to identify or bulk-delete imports.

---

## Site scoping

The form posts a `siteId` field. Imported redirects are created for that site only (`propagationMethod = None`) — they do **not** propagate to other sites automatically.

The `?site=default` query parameter in the CP URL pre-selects the default site in the dropdown, but any site available to the current user can be chosen.

---

## Export round-trip

The export endpoint (`POST beacon/redirects/export`) produces a CSV in exactly this format, so an export → edit → re-import workflow is lossless. Formula-injection characters (`=`, `+`, `-`, `@`, tab, carriage return) at the start of a cell are prefixed with `'` in the exported file as an XSS/injection guard for spreadsheet apps; the importer does **not** strip these prefixes, so exported files should be opened in a proper CSV editor rather than re-imported verbatim after spreadsheet edits.

---

## Minimal valid example

```csv
source,target
/old,/new
/old-blog/*,/blog/*
regex:^/en/(.+)$,/$1
```

## Full example with all columns

```csv
source,target,statusCode,queryStringMode
/old-page,/new-page,301,ignore
/promo,https://promo.example.com/landing,302,preserve
/search,/new-search,301,match
/docs/*,/documentation/*,301,ignore
/legacy/**/index.html,/archive/**,308,ignore
regex:^/p/(\d+)$,/products/$1,301,ignore
```
