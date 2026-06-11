# llms.txt configuration

`llms.txt` is the [llmstxt.org convention](https://llmstxt.org/) — a Markdown summary of a site that AI assistants use to learn what's here, which sections matter, and how to attribute.

Beacon serves `GET /llms.txt` per site. Configure it at **`/admin/beacon/crawlers/llms-txt`**.

## Fields

| Field | Default | Notes |
|---|---|---|
| `enabled` | `true` | Master toggle. When `false`, `/llms.txt` returns 404. |
| `siteNameOverride` | `null` | Heading line (`# Site name`). Falls back to the Craft site's display name. |
| `summary` | `null` | One-line blockquote describing purpose, audience, what's worth looking at. |
| `sections` | `[]` | List of Craft section handles. Each becomes a `## handle` block listing latest live entries. |
| `policyUrl` | `null` | Trust block — URL to your AI use policy. |
| `licenseUrl` | `null` | Trust block — content license (e.g. CC-BY-SA-4.0). |
| `contactEmail` | `null` | Trust block — single point of contact for AI vendors. |
| `preferredAttribution` | `null` | Trust block — short string crawlers should cite, e.g. `"Cite as 'Acme Docs (acme.com)'"`. |
| `fullBody` | `null` | Override the generated body entirely. When set, replaces the auto-rendered sections + trust block with your raw Markdown. |

CRLF in every field is stripped — you can't inject multi-line content from a single setting.

## Output shape

With `summary`, two sections, and a full trust block:

```
# Acme Documentation

> Reference manuals and how-tos for Acme platform integrators.

## docs

- [Quickstart](https://acme.com/docs/quickstart): 5-minute getting-started guide
- [API reference](https://acme.com/docs/api): Endpoint catalog

## blog

- [Spring 2026 release](https://acme.com/blog/spring-2026): Highlights from the latest cycle

## Trust

- Site policy URL: <https://acme.com/ai-policy>
- License URL: <https://creativecommons.org/licenses/by-sa/4.0/>
- Contact: <ai@acme.com>
- Preferred attribution: <Cite as 'Acme Docs (acme.com)'>
```

## How sections render

For each handle in `sections`:

- Beacon queries the latest 5000 live entries from that section.
- Each entry becomes a Markdown link: `- [Title](absolute URL): description`.
- Description comes from the entry's resolved Beacon SEO description (per-entry override → section default → global default → empty).
- Drafts, disabled, and expired entries are excluded.

Sections without entries are emitted as empty headings — useful as a navigation hint, but consider removing them.

## Trust block

The trust block is only emitted when at least one of `policyUrl`, `licenseUrl`, `contactEmail`, `preferredAttribution` is set. Partial blocks are fine — only the populated lines render.

`licenseUrl` should match what you emit in JSON-LD `Organization.licenseUrl` for consistency.

## Caching

The response is cached per site under `RenderCacheType::LlmsTxt` with:

- `max-age=1800` + `stale-while-revalidate=86400`
- Strong ETag
- `Cache-Tag: beacon-llms, beacon-site-{N}` (Cloudflare format)
- `Surrogate-Key: beacon-llms beacon-site-{N}` (Fastly format)

Invalidation:

- Save an entry in a listed section → cache for that site clears.
- Save any of the per-site llms.txt settings → cache for that site clears.
- Manual: `php craft beacon/cache/regenerate-all`.

Cold-regen is mutex-coordinated — concurrent requests after a CDN purge all share a single rebuild.

## Full-body override

When `fullBody` is set, Beacon emits it verbatim (after CRLF stripping). Use this when:

- You want hand-curated copy that doesn't match the auto-generated structure.
- You're seeding from llms.txt examples from another site.
- You want to add custom sections (FAQ, glossary) that don't map to Craft sections.

The trust block is **not** automatically appended to a full-body override — include the trust lines manually if you want them.

## Operational tips

- **Don't list every section.** llms.txt has no native pagination. Sites with hundreds of sections should pick the user-facing ones (docs, guides, blog) and skip internals.
- **Keep the summary short.** Crawlers display it in card form; aim for one sentence.
- **Pair with AI crawler rules.** llms.txt is voluntary; restrict access by setting `Disallow` rules per bot at `/admin/beacon/crawlers/ai-crawlers`.

## See also

- [GEO operational playbook](GEO_OPERATIONAL_PLAYBOOK.md) — full GEO surface inventory + governance.
- [Settings reference](SETTINGS.md) — all other config keys.
