# Security policy

## Reporting a vulnerability

If you've found a security issue in Beacon, please **do not open a public GitHub issue**. Instead, email **hello@anvildev.xyz** with:

- A description of the issue and its impact.
- Steps to reproduce (or a proof-of-concept where applicable).
- The Beacon and Craft versions you observed it on.
- Whether you've shared the finding with anyone else.

You'll receive an acknowledgement within **5 business days**. We aim to provide a remediation timeline within 10 business days of acknowledgement, and to ship a patched release within 90 days of confirmed report (coordinated-disclosure window).

## In scope

- The Beacon plugin (`anvildev/craft-beacon`) and its public endpoints: `/sitemap.xml`, `/sitemap-N.xml`, `/robots.txt`, `/llms.txt`, `/humans.txt`, `/ads.txt`, `/geo/export`, `/beacon/schemamap.json`, the `.md` suffix route, and `/{key}.txt`.
- Beacon's CP controllers under `/admin/beacon/*`.
- Beacon's GraphQL `beacon` field on `EntryInterface`.
- Beacon's event surface (`EVENT_DEFINE_META`, `EVENT_DEFINE_SCHEMAS`, etc.) when consumed by third-party listeners.

## Out of scope

- Issues in Craft CMS itself — report those to [craftcms/cms](https://github.com/craftcms/cms/security).
- Issues caused by misconfigured `request.trustedHosts`, missing CSP headers, or other operator-level deployment choices — see the operator-responsibility notes in the relevant docs.
- Findings that require a compromised admin account (CP admins are trusted; their write surface is by design).
- AI-crawler-rule compliance — `robots.txt` is voluntary; Beacon emits rules but does not enforce them.

## What you can expect

- **Acknowledgement** within 5 business days.
- **Coordinated disclosure**: we ask you not to publish details until a patched release ships or the 90-day window elapses, whichever comes first.
- **Credit** in the changelog and release notes for valid reports, unless you prefer to remain anonymous.
- **No bounty program** at this time, but we're happy to coordinate disclosure with researchers acting in good faith.

## Operator hygiene

Before reporting, please confirm your environment matches Beacon's documented assumptions:

- `request.trustedHosts` is correctly scoped to your CDN / load balancer.
- The Craft user permissions for `EDIT_REDIRECTS`, `EDIT_CRAWLERS`, `EDIT_SITEMAP`, etc. are granted only to users who should hold them.
- Custom Twig in the SEO field or in listeners is reviewed for the same risks as any other template code.
