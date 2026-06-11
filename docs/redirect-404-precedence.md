# Redirect & 404 precedence

Beacon's redirect resolver is a **post-404 listener**, not a route-level pre-empt. This doc spells out the exact order Craft + Beacon evaluate a request so you can predict which rule wins.

## Per-request flow

For any non-CP, non-action request, the order is:

1. **Craft URI rules** (project `config/routes.php`, plugin routes, element URIs)
2. **Element resolution** — entry URI, category, asset, etc.
3. *(everything above succeeds → 200 OK, Beacon doesn't run)*
4. **Yii produces a 404 response**
5. **Beacon's `Response::EVENT_BEFORE_SEND` hook fires** (registered in Beacon's `Plugin::init()`)
6. Beacon checks Redirects table for the current site + URI
7. If a redirect matches → response is rewritten to `301/302/307/308` with a `Location` header
8. If no match → the 404 ships unchanged

Beacon **never** intercepts an existing 200/3xx response. If an entry exists at `/about`, Beacon's redirect rule for `/about` is ignored — element resolution wins.

## Matching precedence within Beacon

When the 404 listener runs, `RedirectService::findRedirect(...)` evaluates rules in this order:

1. **Exact match on `sourceUri`** — short-circuit, O(1) DB lookup
2. **Glob and Regex rules** — walked together by DB order, with **site-specific rules ahead of all-sites rules**

If multiple exact-match rules collide (e.g. a site-scoped rule and an all-sites rule with the same source), the **site-specific one wins** (`preferSiteSpecific`).

If multiple wildcard rules could match a URI, ordering is `sortOrder ASC, id ASC`. New rules are assigned `sortOrder = MAX(sortOrder) + 1` within their site partition (NULL siteId is its own partition), so insertion-order is the default; editors override it via the CP reorder action (`POST beacon/redirects/reorder`). Site-specific rules are still evaluated ahead of all-sites (`siteId IS NULL`) rules at the bucket level — within each bucket, `sortOrder` decides.

## Auto-redirect on slug change

Beacon creates a 301 automatically whenever an Entry's URI changes — this is always on. Saving an entry with a changed `uri` fires `RedirectService::createAutoRedirect()` and inserts a 301 from the old URI to the new one.

The Redirects index marks zero-hit rules as "stale" past a configurable age (cosmetic — Beacon never auto-deletes). The threshold is `staleThresholdDays`, configurable via `config/beacon.php` (default `90`).

Auto-redirects are tagged in the `source` column as `auto-slug` (vs `manual`, `csv-import`) for filtering in CP.

## Coexistence with other 404 handlers

### Whisper

If Whisper is installed:

- **Beacon owns redirects.** Set `redirectsEnabled = false` in Whisper config to disable its 404 listener.
- Whisper continues to provide link-intelligence reporting; just not the redirect resolution.

Running both listeners creates ambiguous chains and double-counted 404 logs. The README documents this boundary.

### Custom listeners

If your site already binds `craft\web\Response::EVENT_BEFORE_SEND`, Yii fires listeners in **registration order**. Plugin order in `config/app.php` decides who runs first. Beacon registers its listener during `init()`, so any listener registered later in the boot will run after Beacon — and may see a rewritten response if Beacon already matched.

To inspect listener order, grep your project's modules and plugins for `EVENT_BEFORE_SEND` bindings; Craft does not ship an introspection CLI for event listeners. If you spot a competing listener, either remove it or call `parent::beforeAction` early so Beacon runs first.

## CSRF, headers, and the rewritten response

When Beacon rewrites a 404 → 3xx response:

- `status_code` is taken from the rule's `statusCode` (one of 301, 302, 307, 308).
- `Location` is the rule's `targetUri`, with capture-group substitution from glob/regex matches applied. Captures are CRLF/NUL-stripped before substitution.
- `response->content`, `data`, and `stream` are cleared.
- Yii's `HeaderCollection::add` handles `\r\n` sanitization as a second line of defense.

Hits are recorded **after** the rewrite (`recordHit()` runs inline). A DB error on hit recording is logged but never blocks the redirect.

## Edge cases

| Scenario | Behaviour |
|---|---|
| Request for `/foo/`, rule for `/foo` (no trailing slash) | Exact match is **literal** — trailing slash is significant. Add both, or use a glob `/foo*`. |
| Rule target points back to a 404 URL | Beacon will redirect, the next request 404s, browser surfaces the 404. No loop detection — author rules carefully. |
| Two enabled rules with the same exact source on the same site | Whichever ID is lower wins (DB order). Use the CP to disable the loser. |
| Redirect to an external host | Supported. `targetUri` may be absolute (`https://…`); CRLF/NUL is still stripped. |
| CP, action, or preview request 404s | Beacon's listener exits early — no redirect attempt. |

## Diagnosing "why didn't my redirect fire?"

1. **Confirm it's a 404.** Look at the original response in DevTools Network — if it's a 200, an entry/route is already winning.
2. **Check site scope.** A rule scoped to site A will not fire on site B unless `siteId IS NULL` (all-sites).
3. **Check `enabled`.** Disabled rules are completely ignored.
4. **Check tie-breaking.** If a less-specific wildcard rule is firing first, reorder.
5. **For regex/glob:** the pattern must match the **leading-slash-trimmed URI** (e.g. `/blog/post`, not `blog/post`). The matcher feeds `'/' . trim($pathInfo, '/')`.
