# Beacon Permissions Reference

Beacon registers two types of access control with Craft: **CP user permissions** (set per user group under *Settings → Users → User Groups*) and **GraphQL schema components** (set per token under *GraphQL → Tokens*).

---

## CP User Permissions

All ten permissions appear under the **Beacon** heading in the user-group permissions screen. Craft Admins bypass all permission checks automatically.

| Permission handle | CP label | What it gates |
|---|---|---|
| `beacon:viewDashboard` | View Beacon dashboard | Read-only access to the Beacon dashboard, analytics widgets (GEO score widget, bot activity, redirect activity, sitemap health, markdown coverage), and the GEO score drill-down panel. Does **not** grant the recompute button. |
| `beacon:editAuthors` | Edit authors | Create, edit, and delete Author elements (`/admin/beacon/authors`). Also required to save per-entry author assignments in the Beacon SEO field. |
| `beacon:editRedirects` | Edit redirects | Create, edit, delete, and bulk-update Redirect elements (`/admin/beacon/redirects`). Includes importing redirects via CSV and running the chain/loop audit. |
| `beacon:editShortLinks` | Edit short links | Create, edit, and delete Short Link elements (`/admin/beacon/short-links`). Does not grant access to the destination analytics beyond what the index table shows. |
| `beacon:editSchemas` | Edit schemas | Configure JSON-LD schema bundles per entry type and manage field-to-property mappings (`/admin/beacon/schemas`). |
| `beacon:editSitemap` | Edit sitemap | Configure the sitemap — which sections are included, priority, change frequency, news sections, per-section overrides (`/admin/beacon/sitemap`). |
| `beacon:editTracking` | Edit tracking | Create, edit, and delete tracking scripts; configure placement (head / body-start / body-end / custom) and environment gating (`/admin/beacon/tracking`). |
| `beacon:editCrawlers` | Edit crawler settings | Manage all four crawler surfaces: AI crawler rules and bot list, llms.txt per-site config, robots.txt per-site config, humans.txt, and ads.txt (`/admin/beacon/crawlers/*`). Covers a broad surface — consider whether editors need all of it before granting. |
| `beacon:editSettings` | Edit Beacon settings | Full access to the Beacon settings screens: Organization / Identity, SEO defaults, hreflang, GEO Markdown export, Commerce sitemap, pagination, and all other global/per-site knobs (`/admin/beacon/settings`). |
| `beacon:editGeoScore` | Manually recompute GEO scores | Unlocks the **Recompute** button in the GEO score drill-down panel, which queues an on-demand `RecomputeGeoScoreJob`. Read-only triage access (viewing scores and drill-down) is granted by `beacon:viewDashboard` — this permission is separate so you can let editors view scores without being able to trigger queue jobs. |

### Recommended group configurations

**Content editor** — can create and attach authors, write redirects, fill in the SEO field:
```
beacon:viewDashboard
beacon:editAuthors
beacon:editRedirects
```

**SEO manager** — everything above plus schemas, sitemap, and recompute access:
```
beacon:viewDashboard
beacon:editAuthors
beacon:editRedirects
beacon:editShortLinks
beacon:editSchemas
beacon:editSitemap
beacon:editGeoScore
```

**Developer / admin** — full access (or just grant the Craft Admin flag):
```
beacon:viewDashboard + all beacon:edit* permissions
```

### Checking a permission in PHP

```php
use anvildev\beacon\helpers\BeaconPermissions;

if (BeaconPermissions::userCan(BeaconPermissions::EDIT_AUTHORS)) {
    // …
}

// Or with the string handle directly:
if (Craft::$app->getUser()->getIdentity()?->can('beacon:editRedirects')) {
    // …
}
```

### Checking a permission in Twig

```twig
{% if currentUser and currentUser.can('beacon:viewDashboard') %}
  <a href="{{ cpUrl('beacon') }}">Open Beacon</a>
{% endif %}
```

---

## GraphQL Schema Components

GraphQL tokens are scoped by schema components. Enable a Beacon component on a token under **GraphQL → Tokens → [token name] → Schema** to expose the corresponding queries and fields.

| Component handle | CP label | What it gates |
|---|---|---|
| `beaconRedirects:read` | Query Beacon redirects | Unlocks `beaconRedirects(siteId, source, type, enabled, search, limit, offset)` (returns a list) and `beaconRedirect(id)` (returns a single redirect or null). |
| `beaconRedirect404s:read` | Query Beacon 404 log | Unlocks `beaconRedirect404s(siteId, handled, limit)` — the 404 log table. Without this component the query field does not exist in the schema. |
| `beaconShortLinks:read` | Query Beacon short links | Unlocks `beaconShortLinks(siteId, enabled, search, limit)` — read-only access to short-link metadata (id, slug, destination, statusCode, clicks, lastClicked, expiresAt). |
| `beaconGeoScore:read` | Read Beacon GEO content score | Unlocks the `geoScore` sub-field on the `beacon` entry field. Without this component, `beacon.geoScore` returns `null` — no error, no data. The component is intentionally separated from the base `beacon` field so score data can be withheld from public tokens. |

### Behaviour when a component is absent

Schema components gate the query at resolution time. When a token lacks a component:

- `beaconRedirects:read` missing → `beaconRedirects` and `beaconRedirect` fields do not appear in the schema introspection at all.
- `beaconGeoScore:read` missing → `beacon.geoScore` is in the schema but resolves to `null`. This is intentional — the base `beacon` field (title, description, canonical, robots, breadcrumbs, schemas) still resolves correctly.

### Public tokens

If you run a public (unauthenticated) GraphQL endpoint, do not grant any `beacon*:read` components — redirect rules and 404 log data are operator-internal. The base `beacon` field on `EntryInterface` (title, description, canonical, robots, schemas, breadcrumbs) requires no component and is always accessible on public tokens.

---

## Relationship between the two systems

CP user permissions and GraphQL schema components are **independent**. A user with `beacon:editRedirects` has no implicit access to the GraphQL `beaconRedirects:read` query — that is a token-level grant. The two systems serve different actors (logged-in CP users vs. API consumers) and are configured separately.
