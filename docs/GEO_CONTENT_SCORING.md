# GEO content scoring

Beacon scores every published entry on how citable it is to AI engines — the
structural properties that recent Generative Engine Optimization (GEO) research
links to a higher citation rate in ChatGPT, Perplexity, Google AI Overviews and
similar systems. The result is a single **0–100 composite** per entry, broken
down into six pillars, surfaced where editors work.

This is a different thing from classic on-page SEO (focus keyphrase, readability,
length). GEO scoring looks at the structural signals AI crawlers reward:
freshness, entity completeness, claim-shaped headings, self-contained answer
chunks, fact density, and outbound citations to authoritative sources.

The score is a **signal, not a ranking**. Every public score implies a
benchmark; this one is explicit about its benchmark — it is **calibrated to the
published GEO research, not to your peers or to your own historical content**.
A 100 means "matches what the literature says AI engines cite," not "best on
your site."

## How to read the score

Each entry gets a composite score from 0 to 100, computed by weighting the six
pillars by their reported effect size and normalising. Higher is better — treat
it as a directional signal rather than a precise grade. The actionable detail
lives in the per-pillar **bands** and the weakest pillar (below), not the
headline number.

Alongside the composite, Beacon reports the **weakest pillar** — the
lowest-scoring pillar for that entry. This is the single most useful number for
an editor: it answers "what do I fix first?"

### Where it surfaces

| Surface | What you see |
|---|---|
| Beacon SEO field chip | A "GEO score NN/100" chip while editing, with a per-pillar tooltip. The chip computes live, so it reflects unsaved structure; the persisted score follows on the next queue cycle. |
| Element-index column | A sortable **GEO score** column on entry listings. Sort ascending to triage your worst entries first. |
| Dashboard widget | The **GEO score** widget shows the site average, the band distribution, and the five worst entries with their weakest pillar — a "what to fix first" list. |
| In-CP drill-down | Per-entry breakdown of all six pillars with **actionable notes** (e.g. "Section 'Setup' has a 12-word lead — expand to 40–75 words"). Includes a **Recompute now** button for editors with the right permission. |

Each pillar is scored 0–10 internally and carries its own band (top 8–10, good
5–7, low 2–4, stale 0–1) plus the notes shown in the drill-down.

## The six pillars

Each pillar is anchored to a specific source so you can challenge the lever, not
just the implementation. Default weights derive from each pillar's reported
effect size; outbound citations carry the most weight, entity completeness and
freshness the least.

### Freshness banding

**What it measures.** How recently the entry was updated, mapped to a four-band
curve. AI engines down-weight stale content.

| Last updated | Pillar score | Band |
|---|---|---|
| < 30 days | 10 | Top |
| < 180 days | 7 | Good |
| < 2 years | 4 | Low |
| Older / no timestamp | 1 | Stale |

**How to improve.** Re-publish substantive updates. A trivial touch-save isn't
the point — the band reflects the entry's `dateUpdated`, and AI engines rarely
cite content older than ~2 years.

**Research basis.** GEO-16 framework (arXiv 2509.10762) — r = 0.68 correlation
between metadata freshness and citation.

### Entity completeness

**What it measures.** How well your site's identity is expressed in structured
data, so knowledge-graph traversal can attribute content to a real entity. It
inspects the Organization (and any attached author) JSON-LD: organization name,
logo, the count of `sameAs` URLs, and whether an author with external
identifiers is attached.

**How to improve.**

- Set the organization name in Beacon settings.
- Add an organization logo asset.
- Add at least three `sameAs` URLs (Wikidata, LinkedIn, Crunchbase, etc.) to
  cross the entity-graph threshold.
- Attach a Beacon Author to the entry, and give that author external identifiers
  (ORCID, Wikidata, LinkedIn) for a full-credit Person node.

**Research basis.** Wikipedia accounts for roughly 47.9% of ChatGPT citation
share — a strong knowledge-graph presence is foundational for traversal.

### Claim-based headings

**What it measures.** What share of your H2/H3 subheadings read as complete
**claims** (subject + verb, ideally with a numeric or named assertion) rather
than bare topics. AI engines quote claim-shaped headings as self-contained
answers.

A heading like "Composer plugins must run before PHP-FPM restarts" scores; a
heading like "Composer plugins" does not. The score is the ratio of claim-shaped
headings to total headings.

**How to improve.** Rephrase topic headings as complete statements. The
drill-down names the offending headings so you can target them. Heading
classification respects the site language.

**Research basis.** Industry tests (May 2026) report a ~2.8× citation lift for
claim-shaped subheadings.

### Chunkability

**What it measures.** Whether each H2 section opens with a **self-contained
40–75-word answer paragraph** — the chunk size AI engines extract and cite. The
score is the share of H2 sections whose lead paragraph lands in range.

Penalised: sections whose lead is too short, too long, or absent (an H2 that
jumps straight into an H3 has no self-contained answer of its own).

**How to improve.** Open each H2 with a 40–75-word paragraph that answers the
section's question on its own, before drilling into subheadings. The drill-down
names sections with out-of-range or missing leads.

**Research basis.** Kopp Online — ~3.1× citation rate on short, self-contained
passages.

### Fact density

**What it measures.** The ratio of citable facts to total word count. The target
is **one fact per 80 words** for the top band. Facts include numeric assertions
(stats, percentages, currency, ranges, units), dates, citation links, and
named-entity assertions.

**How to improve.** Add stats, dates, and citations until you reach the target.
The drill-down tells you how far off you are. Entries under ~200 words are
treated as too thin to score (a "stale" band with a "content too short" note,
not a misleading zero).

**Research basis.** Averi.ai analysis (April 2026) — ~4.2× citation rate above
the 1:80 threshold. The target is configurable (see below).

### Outbound citation density

**What it measures.** The density of outbound links to **authoritative**
sources, weighted by authority tier. This is distinct from fact density: fact
density rewards the *presence* of sources; this pillar rewards their
*authority*.

Beacon ships a curated authority list:

- **Tier 1** (full weight): Wikipedia, Wikidata, and all `*.edu`, `*.gov`,
  `*.gov.uk`, `*.gov.ch`, `*.ac.uk` domains.
- **Tier 2** (0.6 weight): established publishers identified in the research
  corpus (NYT, Guardian, FT, Reuters, AP, BBC, Nature, Science, NEJM, Lancet,
  NIH, WHO, IETF, W3C).

Internal links and links to unlisted domains don't count toward the score.

**How to improve.** Link out to Wikipedia, `.edu`/`.gov` references, or tier-1
publishers. As with fact density, entries under ~200 words are flagged too thin
to score rather than scored zero.

**Research basis.** Princeton GEO paper (arXiv 2311.09735) identified outbound
citation density as the **+115% lever** — the single highest-leverage signal in
the literature. It carries the most weight in the composite.

## Configuration & tuning

GEO scoring is on by default and self-tunes from the research-derived defaults.
The settings below let you adapt it to your editorial policy. All are part of
the Beacon settings surface (CP or `config/beacon.php`).

| Setting | Default | Purpose |
|---|---|---|
| `geoScoreEnabled` | `true` | Master switch for scoring. |
| `geoScoreSectionAllowlist` | `[]` (all) | **Config-file only** (`config/beacon.php`). Section handles whose entries get scored. Empty = every section. |
| `geoScorePillarWeights` | `[]` (research defaults) | Per-pillar weight overrides, keyed by pillar handle. Unspecified pillars keep their default weight. |
| `geoScoreContentRenderMode` | follows GEO Markdown | **Config-file only** (`config/beacon.php`). How the content pillars read entry content (see below). |
| `geoScoreFactDensityTarget` | `80` | **Config-file only**. The "1 fact per N words" target. Soften (e.g. 120) for narrative content; tighten (e.g. 60) for reference docs. |
| `geoScoreAuthorityDomainOverrides` | `[]` | **Config-file only**. Add domains to the authority list (with `tier` 1 or 2) or disable a bundled default (`enabled: false`). |

### Section allowlist

Leave `geoScoreSectionAllowlist` empty to score every section, or list the
section handles you care about. Entries outside the allowlist show no chip and
are never scored.

### Per-pillar weight overrides

The composite is a weighted sum normalised across pillars; weights are ratios,
not percentages. To emphasise a pillar — say you care most about outbound
citations — raise its weight in `geoScorePillarWeights` keyed by handle
(`freshnessBanding`, `entityCompleteness`, `claimBasedHeadings`, `chunkability`,
`factDensity`, `outboundCitationDensity`). Any pillar you don't list keeps its
research-derived default.

### Content render mode

The structural pillars (claim-based headings, chunkability, fact density,
outbound citations) need to read the entry's content. `geoScoreContentRenderMode`
controls how:

- `bodyField` — read the configured body field only. Faster; misses content
  outside that field.
- `fullRender` — render the entry's Twig template and walk the result. More
  accurate (catches content composed across Matrix blocks and JS islands AI
  crawlers can't execute), at the cost of a render per compute.
- Empty (default) — follow `geoMarkdownFullPageRender`, so you configure the
  render strategy once and both surfaces agree.

### Authority-domain overrides

Use `geoScoreAuthorityDomainOverrides` to extend or trim the outbound-citation
authority list — add your industry's reference sites at the appropriate tier, or
disable a bundled default you don't want counted. Wildcard domains (e.g.
`*.edu`) are supported. Clearing the overrides restores the bundled defaults.

### A note on detection modes

The claim and fact-density pillars run on built-in heuristics. The settings
`geoScoreClaimDetectionMode` and `geoScoreFactDetectionMode` accept only
`heuristic` today; an `llm` mode (higher-fidelity classification via a BYO API
key) is reserved for a future release and is rejected at validation until it
ships.

**Claim detection heuristic (`ClaimBasedHeadingsPillar`)**

A heading is classified as a *claim* (a complete, quotable statement) rather than
a *topic phrase* when it satisfies both conditions:

1. At least 4 tokens (words stripped of punctuation)
2. At least one token matches a finite verb stem from the site's language set

Built-in stem sets: English (70+ stems — copulas, modals, common transitive verbs)
and German. All other languages fall back to the English set. The site language is
read from Craft's locale for the current site (BCP-47 primary subtag, e.g. `de` from
`de-CH`). Verb matching uses stem + common suffix: `help` matches `helps`, `helped`,
`helping`, `helps`; suffix list is `s`, `es`, `ed`, `d`, `ing`, `ies`, `ied`.

False positives are accepted by design (e.g. `reducer` matching `reduce`) — the cost
is one mis-classified heading, not a pillar-direction error.

Examples:

| Heading | Claim? | Reason |
|---|---|---|
| "PHP Performance Fundamentals" | No | Noun phrase, no verb |
| "PHP is faster than Python in I/O" | Yes | ≥4 tokens + copula `is` |
| "Why does React re-render on every state change?" | Yes | Question, but ≥4 tokens + auxiliary `does` |
| "Docker reduces memory overhead" | Yes | `reduce` + suffix `s` |
| "Async / Await" | No | < 4 tokens |

**Fact density heuristic (`FactDensityPillar`)**

Four independent detectors count citable facts in prose text (headings, paragraphs,
lists, tables — code blocks are excluded):

1. **Numeric assertions** — percentages (`23%`), currencies (`$4.2M`), SI units
   (`500 MB`, `120 MHz`, `37°C`), bare integers ≥ 2 (excluding standalone years),
   decimals (`3.14`), ranges (`100–200`), multipliers (`5×`).
2. **Date assertions** — ISO 8601 dates (`2026-05-26`), English/German named months
   (`May 2026`, `März 2025`), contextual years (`since 2019`, `by 2030`), quarter
   notation (`Q3 2025`).
3. **Named entity assertions** — Title-cased noun phrases (1–3 words) followed within
   a few words by a reporting verb (`announced`, `launched`, `acquired`, `confirmed`,
   etc.). Sentence-initial pronouns are excluded to suppress false positives.
4. **Outbound citation links** — each external link counts as one citable fact
   (authority quality is scored separately by `OutboundCitationDensityPillar`).

The target density is 1 fact per 80 words by default. Tune with
`geoScoreFactDensityTarget` — a lower number is stricter (requires more facts per
word). Content under 50 words is too short to score and receives a `stale` band with
a note suggesting expansion.

## Headless / GraphQL access

The `beacon` field exposes a `geoScore` sub-field returning the composite plus
the per-pillar breakdown. The resolver is **lazy** — entries that don't select
`geoScore` never touch the score table — and the field is gated by the
`beaconGeoScore:read` schema component. Without that component on the token,
`geoScore` returns `null`.

```graphql
{
  entries(section: "blog", limit: 5) {
    title
    beacon {
      geoScore {
        score              # 0–100 composite
        weakestPillar      # handle of the lowest-scoring pillar (fix this first)
        computedAt         # ISO-8601 of the last persisted compute
        pillars {
          handle           # freshnessBanding, entityCompleteness, claimBasedHeadings,
                           # chunkability, factDensity, outboundCitationDensity
          score            # 0–10
          band             # top | good | low | stale
          notes            # actionable feedback strings
        }
      }
    }
  }
}
```

### When the score recomputes

Scoring is asynchronous. Saving an in-scope entry enqueues a recompute job;
the persisted score (and `computedAt`) lands when your queue runner next cycles,
so it may **lag the editor's save by one cycle**. The SEO-field chip computes
live for instant display, but the stored row — the one GraphQL, the widget, and
the element-index column read — arrives via the queue. Editors can also force a
recompute from the drill-down's **Recompute now** button.

Reading the GraphQL field requires the `beaconGeoScore:read` schema component.
Browsing the dashboard widget and drill-down in the CP requires the
`beacon:viewDashboard` permission; the **Recompute now** button additionally
requires `beacon:editGeoScore`, so you can grant triage access without recompute
rights.

## See also

- [README](../README.md) — Beacon overview and the GraphQL surface.
- [GEO operational playbook](GEO_OPERATIONAL_PLAYBOOK.md) — the wider GEO feature
  set (llms.txt, AI crawler rules, Markdown export) that scoring complements.
- [Extensibility cookbook](EXTENSIBILITY_COOKBOOK.md) — event-point conventions,
  including registering a custom scoring pillar via `RegisterGeoScorePillarsEvent`.
- [Settings reference](SETTINGS.md) — every `geoScore*` setting in context.
