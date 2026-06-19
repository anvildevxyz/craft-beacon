# AI usage & content-licensing signals

Beacon lets you declare how AI systems may use your content and fans that one
choice out across every surface a crawler might check. Nothing is emitted until
you opt in — a fresh install defaults to **Allow** everywhere.

## Policy vocabulary

| Policy | Meaning |
|---|---|
| `allow` | No restriction (default). Emits nothing. |
| `no-train` | Opt out of AI/ML training & data mining. |
| `no-generative-ai` | Opt out of generative-answer ("AI input") use. |
| `no-ai` | Opt out of both training and generative use. |

## Where it's set (most specific wins)

1. **Per entry** — the *AI usage policy* dropdown on the Beacon SEO field.
2. **Per section** — Settings → Content → each section's *AI usage policy*.
3. **Site default** — Settings → Content → *Site default AI-usage policy*.

Each level can defer with *Inherit*, so resolution is entry → section → site default → `allow`.

## Surfaces emitted for a restrictive policy

| Surface | Scope | Example |
|---|---|---|
| `robots` meta + `X-Robots-Tag` | per page | `<meta name="robots" content="noai, noimageai">` |
| `tdm-reservation` / `tdm-policy` meta | per page | `<meta name="tdm-reservation" content="1">` |
| `Content-Usage` response header | per page | `Content-Usage: ai-train=n` |
| TDMRep manifest | per location | `/.well-known/tdmrep.json` (404 when nothing is reserved) |
| Content Signals | site (robots.txt) | `Content-Signal: ai-train=no` |

### Scope notes

- **Per-page surfaces** (meta tags, headers) reflect the exact entry-level resolved policy.
- **TDMRep** is location-based, so the manifest carries a `/` entry for the site default plus one entry per restrictive section that has a static URL prefix (e.g. `blog/{slug}` → `/blog/`).
- **Content Signals** in `robots.txt` is a site-wide directive (the spec is not reliably path-scoped), so it reflects the site default; per-section restrictions are echoed as `robots.txt` comments and carried losslessly by TDMRep + per-page meta.

## What honors what

These signals are **advisory**. `noai`/`noimageai` are de-facto conventions
honored by a growing set of crawlers; TDMRep has legal backing under the EU
CDSM Directive / AI Act; Content Signals is Cloudflare's robots.txt extension.
Beacon does not block crawlers beyond `robots.txt` — enforcement is out of scope.
