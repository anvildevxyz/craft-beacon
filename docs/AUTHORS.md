# Authors & E-E-A-T

Beacon ships a first-class Author element to support Google's E-E-A-T (Experience, Expertise, Authoritativeness, Trust) signals on entry pages. Authors are reusable across entries and emit `Person` JSON-LD with credentials, expertise areas, and authoritative profile links.

## Creating Authors

Author elements live at **`/admin/beacon/authors`**.

The author edit screen keeps the fields plain-language; this table is the authoritative mapping from each field to the `Person` JSON-LD it produces.

| Field | Type | JSON-LD output |
|---|---|---|
| Name (`title`) | string | `Person.name` |
| Job title | string | `Person.jobTitle` |
| Description | string | `Person.description` |
| Headshot | asset | `Person.image` (Google's knowledge panel prefers a square photo) |
| Expertise topics | list of strings | `Person.knowsAbout` (e.g. `["SEO", "Technical writing"]`) |
| Credentials | list of strings | One `EducationalOccupationalCredential` node each, under `Person.hasCredential` (e.g. `["PhD Computer Science"]`) |
| sameAs URLs | list of URLs | `Person.sameAs` — authoritative profile links (LinkedIn, ORCID, personal site) that search engines correlate against the Knowledge Graph |
| Affiliation | list of strings | `Person.affiliation` |
| Works for | list of strings | `Person.worksFor` |
| Alumni of | list of strings | `Person.alumniOf` |

Authors are **localized**: each site can hold its own translation of an author's title, job title, and field values.

## Attaching Authors to entries

On any entry with a Beacon SEO field:

1. Open the SEO field.
2. Expand **Authors**.
3. Pick one or more Author elements.

Beacon emits a `Person` JSON-LD node per attached author and references them from the entry's `Article` / `BlogPosting` schema (as `author`). When multiple authors are attached, the node becomes an array.

Headless (GraphQL): the SEO field's `authorIds` is a list of element ids; resolve to Author elements with a regular `craft.beacon.authors()` element query or via GraphQL.

## JSON-LD output

A populated Author produces:

```json
{
  "@type": "Person",
  "name": "Ada Lovelace",
  "jobTitle": "Principal Engineer",
  "knowsAbout": ["Compilers", "Numerical analysis"],
  "hasCredential": ["MS Mathematics, University of London"],
  "sameAs": [
    "https://www.linkedin.com/in/adalovelace",
    "https://orcid.org/0000-0000-0000-0000"
  ]
}
```

When attached to an entry rendering an Article schema:

```json
{
  "@type": "Article",
  "headline": "On the analytical engine",
  "author": {
    "@type": "Person",
    "name": "Ada Lovelace",
    "...": "..."
  }
}
```

## Twig API

```twig
{# Fetch Authors directly #}
{% set authors = craft.beacon.authors().limit(10).all() %}

{% for author in authors %}
  <article>
    <h3>{{ author.title }}</h3>
    {% if author.jobTitle %}<p>{{ author.jobTitle }}</p>{% endif %}
    {% if author.expertise %}
      <ul>
        {% for topic in author.expertise %}<li>{{ topic }}</li>{% endfor %}
      </ul>
    {% endif %}
  </article>
{% endfor %}
```

The element query supports the standard Craft criteria (`id`, `site`, `siteId`, `title`, `dateUpdated`, etc.).

## Relation to global identity

The plugin emits one identity JSON-LD node per page based on `Settings → Organization`. If `identityType = 'Person'`, that node is a `Person` for the site's primary author. Per-entry Authors are **additional** Person nodes — they don't replace the global identity.

For sites with a single author (a personal blog), the simplest setup:

- Set `identityType = 'Person'` and fill the Organization screen with the author's name + sameAs.
- Skip the per-entry Author attachment.

For sites with multiple contributors:

- Keep `identityType = 'Organization'` and fill the Organization screen with the publisher.
- Create one Author element per contributor and attach per entry.

## Public author profiles

Enable at **Settings → Authors**. Off by default.

When on, every Author element becomes addressable at `/{prefix}/{slug}` (prefix configurable, default `authors` → `/authors/jane-doe`) and is listed in the sitemap. The page renders with the built-in template at `templates/_public/author-profile.twig`; override it by dropping your own at `templates/beacon/public/author-profile.twig` in your project.

After enabling, run this once so existing authors get their per-site URIs populated:

```bash
php craft resave/elements --type="anvildev\beacon\elements\AuthorElement"
```

### Does this help SEO/GEO?

Two distinct things are at play, and only the *pages* are gated by this setting:

1. **Author schema on content (always on).** Each author already emits a `Person` JSON-LD node into the content they wrote (see [JSON-LD output](#json-ld-output)). That's the core E-E-A-T signal and needs no public page.
2. **Public author pages (this setting).** A crawlable author page gives the author a canonical entity URL that article `author` references can point to, anchoring the entity graph for both search engines and AI answer engines (provenance/authority). It's a real E-E-A-T surface — bio, credentials, `sameAs` to ORCID/LinkedIn/Wikipedia.

**When to enable:** editorial, news, blog, and YMYL sites where authorship is a ranking/authority factor *and* bios are substantive. **When to skip:** corporate, product, or single-author sites — thin author pages (bare name + avatar) add little and can dilute crawl budget. The author schema on content gives you the E-E-A-T win either way.

## See also

- [Extensibility cookbook](EXTENSIBILITY_COOKBOOK.md) — adding custom JSON-LD nodes (e.g. project-specific `Person.affiliation`).
