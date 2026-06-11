# Beacon — Extensibility cookbook

Beacon exposes a small, stable event + Twig API. This cookbook collects the patterns we recommend for common customizations. All examples assume PHP 8.2+, Craft 5, and that you're writing code inside a custom module (`config/app.php` referenced).

## Event map

| Constant | Class | Fires |
|---|---|---|
| `Plugin::EVENT_DEFINE_META` | `DefineMetaEvent` | After meta is resolved (global → section → entry), before tags render. Mutate `$event->meta`. |
| `Plugin::EVENT_AFTER_RESOLVE_META` | `AfterResolveMetaEvent` | Read-only inspection point after `DefineMetaEvent`. Use for logging / debug. |
| `Plugin::EVENT_DEFINE_META_TAGS` | `DefineMetaTagsEvent` | Final mutable container of rendered `<meta>` tags. Add/remove/replace individual tags by key. |
| `Plugin::EVENT_DEFINE_SCHEMAS` | `DefineSchemasEvent` | When JSON-LD graph is being assembled. Mutate `$event->holder->nodes`. May fire multiple times per request — listeners must be idempotent. |
| `Plugin::EVENT_REGISTER_SITEMAP_URLS` | `RegisterSitemapUrlsEvent` | While building `sitemap.xml`. Use `$event->pushUrl(...)` for synthetic URLs (CDN-served pages, off-Craft routes). |
| `TrackingProviderRegistry::EVENT_REGISTER_PROVIDERS` | `RegisterTrackingProvidersEvent` | Add custom tracking providers (Plausible, in-house analytics, etc.). See [tracking-provider-cookbook.md](tracking-provider-cookbook.md). |
| `RedirectService::EVENT_BEFORE_MATCH_REDIRECT` | `BeforeMatchRedirectEvent` | Before built-in redirect matching runs. Short-circuit by assigning `$event->redirect`, or veto with `$event->isHandled = true; $event->redirect = null`. |
| `RedirectService::EVENT_AFTER_MATCH_REDIRECT` | `AfterMatchRedirectEvent` | After a redirect matched. Rewrite by assigning `$event->redirect` to a new {@see Redirect}; cancel with `$event->redirect = null`. |
| `RedirectMatcher::EVENT_REGISTER_REDIRECT_TYPES` | `RegisterRedirectTypesEvent` | Register custom matching algorithms via `CustomRedirectMatcherInterface`. Fires lazily on first wildcard dispatch per request. |
| `GeoScoreService::EVENT_REGISTER_GEO_SCORE_PILLARS` | `RegisterGeoScorePillarsEvent` | Add custom GEO-score pillars via `PillarComputerInterface`. Fires once per request on first `GeoScoreService::compute()`. |

All event classes live in `anvildev\beacon\events\` and are constructor-typed; PHPStan / IDE autocompletion works out of the box.

> The redirect events live on `RedirectService` / `RedirectMatcher` and the GEO-score event on `GeoScoreService` — pass that class (not `Plugin::class`) as the first argument to `Event::on()`.

## Recipe 1: Add a custom canonical URL strategy

Marketing wants the canonical of `/blog/{slug}` entries to always point to a `?utm_source=` parameter stripped version, even for entries that came from a campaign.

```php
use anvildev\beacon\events\DefineMetaEvent;
use anvildev\beacon\Plugin;
use yii\base\Event;

Event::on(
    Plugin::class,
    Plugin::EVENT_DEFINE_META,
    static function (DefineMetaEvent $event): void {
        if ($event->meta->canonical === null) {
            return;
        }
        // Strip UTM params from canonicals
        $parts = parse_url($event->meta->canonical);
        if (isset($parts['query'])) {
            parse_str($parts['query'], $params);
            $kept = array_filter(
                $params,
                static fn (string $k): bool => !str_starts_with($k, 'utm_'),
                ARRAY_FILTER_USE_KEY
            );
            $event->meta->canonical = ($parts['scheme'] ?? 'https')
                . '://' . ($parts['host'] ?? '')
                . ($parts['path'] ?? '/')
                . ($kept ? '?' . http_build_query($kept) : '');
        }
    }
);
```

## Recipe 2: Inject a custom `<meta>` tag

The build environment wants every page to carry `<meta name="X-Deploy-SHA" content="...">`. Use `EVENT_DEFINE_META_TAGS` so the tag participates in Beacon's render cache.

```php
use anvildev\beacon\events\DefineMetaTagsEvent;
use anvildev\beacon\Plugin;
use yii\base\Event;

Event::on(
    Plugin::class,
    Plugin::EVENT_DEFINE_META_TAGS,
    static function (DefineMetaTagsEvent $event): void {
        $event->tags['x-deploy-sha'] = [
            'attr'    => 'name',
            'name'    => 'X-Deploy-SHA',
            'content' => getenv('GIT_SHA') ?: 'unknown',
        ];
    }
);
```

Removing a tag is as simple as `unset($event->tags['robots'])`. The key is the deduplication identifier — last write wins.

## Recipe 3: Add a custom JSON-LD node

You're shipping a `Course` schema for entries in the `courses` section. Match the entry context, build the node, **replace by `@type` to stay idempotent** (the event may fire twice per request).

```php
use anvildev\beacon\events\DefineSchemasEvent;
use anvildev\beacon\Plugin;
use yii\base\Event;

Event::on(
    Plugin::class,
    Plugin::EVENT_DEFINE_SCHEMAS,
    static function (DefineSchemasEvent $event): void {
        $entry = $event->entry;
        if ($entry === null || $entry->getSection()?->handle !== 'courses') {
            return;
        }

        // Idempotency: drop any existing Course node before adding this one
        $event->holder->nodes = array_values(array_filter(
            $event->holder->nodes,
            static fn (array $n): bool => ($n['@type'] ?? null) !== 'Course'
        ));

        $event->holder->nodes[] = [
            '@type' => 'Course',
            'name' => $entry->title,
            'description' => (string)($entry->summary ?? ''),
            'provider' => [
                '@type' => 'Organization',
                'name' => 'Acme Academy',
            ],
        ];
    }
);
```

If the rendered output is wrong on repeated calls (e.g. duplicate `Course` nodes), your listener almost certainly violates the idempotency contract.

## Recipe 4: Synthetic sitemap URLs

Beacon's sitemap only walks Craft entries by default. To add static landing pages served outside Craft (e.g. a `/pricing` route handled by Next.js fronting your CMS):

```php
use anvildev\beacon\events\RegisterSitemapUrlsEvent;
use anvildev\beacon\Plugin;
use yii\base\Event;

Event::on(
    Plugin::class,
    Plugin::EVENT_REGISTER_SITEMAP_URLS,
    static function (RegisterSitemapUrlsEvent $event): void {
        $event->pushUrl(
            loc: 'https://example.com/pricing',
            lastmod: (new \DateTimeImmutable())->format('c'),
            changefreq: 'weekly',
            priority: 0.8,
        );
    }
);
```

`pushUrl()` rows take precedence over core Beacon rows for the same `loc` (last writer wins).

## Recipe 5: Per-template overrides from Twig

For one-off overrides (a landing page that needs a specific OG image but no field exists for it), use the Twig API. See [TWIG_API.md](TWIG_API.md) for the full reference — every function, parameter, call-order rules, and headless usage patterns.

```twig
{# at the top of templates/landing.twig #}
{% do craft.beacon.set('ogImage', siteUrl('campaign/og.png')) %}
{% do craft.beacon.set('title', 'Spring 2026 launch') %}
{% do craft.beacon.set('description', 'Limited-time pricing for early adopters.') %}

{# breadcrumbs override #}
{% do craft.beacon.setBreadcrumbs([
    { name: 'Home', url: siteUrl('/') },
    { name: 'Campaigns', url: siteUrl('/campaigns') },
    { name: entry.title, url: entry.url },
]) %}

{# one-off JSON-LD without writing a listener #}
{% do craft.beacon.addSchema({
    '@type': 'Event',
    name: 'Spring launch',
    startDate: '2026-04-01',
}) %}
```

`set()` is shallow — it overrides a single resolved field after section/entry merge. For multi-field overrides, call it multiple times or use `EVENT_DEFINE_META`.

## Recipe 6: Pagination on listing templates

When rendering a paginated archive, Beacon needs to know about the pagination state to emit `<link rel="prev/next">` and shape the canonical. All pagination knobs live on this call — there are no global pagination settings:

```twig
{% paginate entries.limit(10) as pageInfo, paged %}

{% do craft.beacon.setPagination({
    page: pageInfo.currentPage,
    pageCount: pageInfo.totalPages,
    baseUrl: pageInfo.basePageUrl,
    pageParam: pageInfo.pageTrigger,     // default: 'page'
    canonicalMode: 'firstPageCanonical', // default; or 'self'
    appendPageToTitle: false,            // default; or true
}) %}
```

| Key | Default | Notes |
|---|---|---|
| `page` | `1` | Current page number (1-indexed). |
| `pageCount` | `null` | Total page count. Required for `<link rel="next">` on the last page to be suppressed correctly. |
| `baseUrl` | `''` | The page-1 URL. Required — pagination tags suppress entirely without it. |
| `pageParam` | `'page'` | URL query parameter your routes use (`?page=2`, `?p=2`, etc.). |
| `canonicalMode` | `'firstPageCanonical'` | `'firstPageCanonical'` collapses all pages' canonicals to page 1; `'self'` keeps each page's canonical pointing at itself, and Beacon rewrites hreflang alternates to match. |
| `appendPageToTitle` | `false` | When `true`, appends ` — Page N` to the resolved title and OG title from page 2 onward. |

Omitted keys take the defaults — call sites only specify what they want to override.

## Recipe 7: Logging resolved meta for audit

Use the read-only event for compliance/audit hooks — it can't accidentally mutate state.

```php
use anvildev\beacon\events\AfterResolveMetaEvent;
use anvildev\beacon\Plugin;
use yii\base\Event;
use Craft;

Event::on(
    Plugin::class,
    Plugin::EVENT_AFTER_RESOLVE_META,
    static function (AfterResolveMetaEvent $event): void {
        $entry = $event->entry;
        if ($entry === null) {
            return;
        }
        Craft::info(sprintf(
            '[seo-audit] %s | robots=%s | canonical=%s',
            $entry->getSection()?->handle ?? '?',
            $event->meta->robots !== [] ? implode(',', $event->meta->robots) : '-',
            $event->meta->canonical ?? '-',
        ), 'seo-audit');
    }
);
```

Avoid this for PII logging — entry titles and canonicals leak in shipped logs. Log identifiers + state, not content.

## Recipe 8: Conditional schema based on user role

Hide the `Organization` JSON-LD for logged-in editors (e.g. they previewed a draft they shouldn't authoritatively label):

```php
use anvildev\beacon\events\DefineSchemasEvent;
use anvildev\beacon\Plugin;
use yii\base\Event;
use Craft;

Event::on(
    Plugin::class,
    Plugin::EVENT_DEFINE_SCHEMAS,
    static function (DefineSchemasEvent $event): void {
        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null || !$user->can('previewEntries')) {
            return;
        }
        $event->holder->nodes = array_values(array_filter(
            $event->holder->nodes,
            static fn (array $n): bool => ($n['@type'] ?? null) !== 'Organization'
        ));
    }
);
```

## Recipe 9: Extend the schema-type list

Out of the box, the SEO field's "Add schema" modal offers 26 curated types covering the high-leverage categories: editorial (`Article` / `BlogPosting` / `NewsArticle` / `TechArticle` / `ScholarlyArticle`), commerce (`Product`), activities (`Recipe` / `HowTo` / `Course`), discovery (`FAQPage` / `QAPage` / `ItemList` / `AboutPage` / `ContactPage`), reviews, people/orgs (`Person` / `Organization` / `LocalBusiness` / `Restaurant` / `Store`), events + jobs (`Event` / `JobPosting`), media (`VideoObject` / `ImageObject` / `PodcastEpisode`), and `SoftwareApplication`. Each one ships with required / recommended / optional property tiers + help copy in the property picker.

Authors who need a one-off additional type can register it explicitly:

```php
// config/beacon.php
return [
    'schemaTypes' => ['MusicAlbum', 'Drug', 'TouristAttraction'],
];
```

Sites that want the **full schema.org graph** (~900 types — every class published in schema.org's current JSON-LD release) can flip a single flag instead:

```php
// config/beacon.php
return [
    'fullSchemaCatalogue' => true,
];
```

With `fullSchemaCatalogue => true`, the modal dropdown carries every schema.org type and the property picker falls back to the type's full property set inherited from the schema.org hierarchy (no required/recommended tier — schema.org itself doesn't publish that metadata; only the curated 26 carry it). Pick this when you want maximum breadth at the cost of a longer dropdown.

The catalogue is generated from schema.org's published JSON-LD by `tools/generate-schema-catalogue.php` and committed as `src/schemas/GeneratedSchemaCatalogue.php`. Regenerate when schema.org cuts a new release.

## Recipe 10: Register a custom GEO-score pillar

The six built-in pillars (see [GEO content scoring](GEO_CONTENT_SCORING.md)) cover the common signals, but you can add your own — say, an internal-linking density pillar. Implement `PillarComputerInterface` and register it through `RegisterGeoScorePillarsEvent`. The new pillar's handle gets a default weight of `1.0`, overridable via `geoScorePillarWeights` in `config/beacon.php`.

```php
use anvildev\beacon\events\RegisterGeoScorePillarsEvent;
use anvildev\beacon\models\GeoPillarScore;
use anvildev\beacon\services\GeoScoreService;
use anvildev\beacon\services\scoring\PillarComputerInterface;
use anvildev\beacon\services\scoring\PillarContext;
use yii\base\Event;

final class InternalLinkingPillar implements PillarComputerInterface
{
    // A plain-string handle adds a brand-new pillar; built-ins return a GeoScorePillar case.
    public function pillar(): string
    {
        return 'internalLinking';
    }

    public function compute(PillarContext $ctx): GeoPillarScore
    {
        // $ctx->element, $ctx->siteId, and $ctx->ast() (lazy content AST) are available.
        $links = substr_count(strtolower((string) $ctx->element->getFieldValue('body')), '<a ');
        $score = GeoPillarScore::clampScore((int) min(10, $links));

        return new GeoPillarScore(
            pillar: 'internalLinking',
            score: $score,
            band: GeoPillarScore::bandFor($score),
            notes: $links < 3 ? ['Add more internal links — aim for 3+ per article.'] : [],
        );
    }
}

Event::on(
    GeoScoreService::class,
    GeoScoreService::EVENT_REGISTER_GEO_SCORE_PILLARS,
    static function(RegisterGeoScorePillarsEvent $event): void {
        $event->pillars[] = new InternalLinkingPillar();
    },
);
```

The event fires once per request, the first time `GeoScoreService::compute()` runs, so registration is cheap. `compute()` returns a 0–10 banded score; the composite 0–100 score blends every pillar by weight. Your pillar's `handle`, `score`, `band`, and `notes` flow straight through to the CP score drill-down and the GraphQL `geoScore.pillars` field.

### PillarContext — what's available

`PillarContext` carries three items:

| Property / Method | Type | Description |
|---|---|---|
| `$ctx->element` | `ElementInterface` | The entry being scored. Cast to `Entry` if you need entry-type or field access. |
| `$ctx->siteId` | `int` | The site the score is computed for. |
| `$ctx->ast()` | `list<ContentNode>` | Lazy flat AST of the entry's content in document order. The walk runs at most once per `compute()` call — multiple pillars share the result. |

### ContentNode structure

`ast()` returns a flat `list<ContentNode>`. Each node is one content block:

| `$node->type` | `$node->level` | `$node->text` | `$node->wordCount` | `$node->items` | `$node->href` / `->isInternal` |
|---|---|---|---|---|---|
| `'heading'` | `1`–`6` | Plain heading text | Pre-computed | `[]` | n/a |
| `'paragraph'` | `null` | Plain paragraph text | Pre-computed | `[]` | n/a |
| `'list'` | `null` | Item concatenation | Pre-computed | One string per `<li>` | n/a |
| `'table'` | `null` | Flattened cell text | Pre-computed | `[]` | n/a |
| `'code'` | `null` | Raw code body | Pre-computed | `[]` | n/a |
| `'link'` | `null` | Link anchor text | Pre-computed | `[]` | Populated |

Use the type constants rather than string literals: `ContentNode::TYPE_HEADING`, `::TYPE_PARAGRAPH`, `::TYPE_LIST`, `::TYPE_TABLE`, `::TYPE_CODE`, `::TYPE_LINK`.

### AST-based pillar example

Rewrites the internal-linking example from the cookbook to use the AST rather than raw HTML counting:

```php
use anvildev\beacon\services\scoring\ContentNode;
use anvildev\beacon\services\scoring\PillarComputerInterface;
use anvildev\beacon\services\scoring\PillarContext;
use anvildev\beacon\models\GeoPillarScore;

final class InternalLinkingPillar implements PillarComputerInterface
{
    public function pillar(): string { return 'internalLinking'; }

    public function compute(PillarContext $ctx): GeoPillarScore
    {
        $internalLinks = 0;
        foreach ($ctx->ast() as $node) {
            if ($node->type === ContentNode::TYPE_LINK && $node->isInternal) {
                $internalLinks++;
            }
        }
        $score = GeoPillarScore::clampScore(min(10, $internalLinks * 2));
        return new GeoPillarScore(
            pillar: 'internalLinking',
            score: $score,
            band: GeoPillarScore::bandFor($score),
            notes: $internalLinks < 3 ? ['Add 3+ internal links to improve discoverability.'] : [],
        );
    }
}
```

### Performance note

`$ctx->ast()` triggers a full content walk (Twig render or field read depending on `geoScoreContentRenderMode`). Cheap pillars that don't need content — like Freshness or Entity completeness — should never call `ast()`. If your pillar is invoked on every queue run, prefer fast field lookups over a full render. The built-in structural pillars share one walk per `compute()` call so the cost is amortised across all of them.

## Where listeners should live

| Listener kind | Recommended home |
|---|---|
| Per-project tweaks (this campaign, this section) | A small custom module bootstrapped from `config/app.php`. |
| Shared across multiple sites you maintain | A composer package wrapping the listeners + tests. |
| Inside another Craft plugin you ship | The plugin's `init()`. Document the dependency on Beacon in `composer.json` (`"require"`). |

Avoid registering Beacon listeners from inside `templates/` Twig — those run after request init and may miss meta events.

## Stability guarantees

Beacon **freezes the public event signatures** for v1.x:

- Constructor arg order on event classes will not change.
- Public properties on event objects will not be renamed.
- Twig API (`craft.beacon.head()`, `set()`, `addSchema()`, `schemas()`, `setPagination()`, `setBreadcrumbs()`, `breadcrumbs()`, `meta()`, `tags()`, `setTag()`, `removeTag()`, `bodyStart()`, `bodyEnd()`, `trackingFor()`, `debug()`) will not be removed or renamed.

New events and Twig methods may be added; existing ones are append-only. The plugin's `CHANGELOG.md` records the introduction version (`@since X.Y.0`) on every event class.

## Redirect extensibility

Three hooks plug into the redirect engine. They fire whether or not a rule
matches (so analytics use cases can observe 404s too).

### Recipe: skip redirects for logged-in users

Useful for staging or "editor preview" flows where you don't want the
public 301 to fire while the editor is testing the new entry.

```php
use anvildev\beacon\events\BeforeMatchRedirectEvent;
use anvildev\beacon\services\RedirectService;
use Craft;
use yii\base\Event;

Event::on(
    RedirectService::class,
    RedirectService::EVENT_BEFORE_MATCH_REDIRECT,
    static function (BeforeMatchRedirectEvent $event): void {
        if (Craft::$app->getUser()->getIdentity() !== null) {
            $event->isHandled = true;
            $event->redirect = null;
        }
    },
);
```

### Recipe: append an affiliate tag after match

```php
use anvildev\beacon\events\AfterMatchRedirectEvent;
use anvildev\beacon\models\Redirect;
use anvildev\beacon\services\RedirectService;
use yii\base\Event;

Event::on(
    RedirectService::class,
    RedirectService::EVENT_AFTER_MATCH_REDIRECT,
    static function (AfterMatchRedirectEvent $event): void {
        $r = $event->redirect;
        if ($r === null || !str_starts_with($r->resolvedTarget, 'https://partner.example')) {
            return;
        }
        $sep = str_contains($r->resolvedTarget, '?') ? '&' : '?';
        $event->redirect = new Redirect(
            id: $r->id,
            siteId: $r->siteId,
            sourceUri: $r->sourceUri,
            targetUri: $r->targetUri,
            statusCode: $r->statusCode,
            type: $r->type,
            resolvedTarget: $r->resolvedTarget . $sep . 'aff=beacon',
            queryStringMode: $r->queryStringMode,
        );
    },
);
```

### Recipe: register a `legacy-id` matcher

Old WordPress imports often leave entries with an `oldArticleId` field. A
custom matcher lets you store one rule whose pattern is the field handle
and let Beacon do the lookup at request time.

```php
use anvildev\beacon\events\RegisterRedirectTypesEvent;
use anvildev\beacon\services\CustomRedirectMatcherInterface;
use anvildev\beacon\services\RedirectMatcher;
use craft\elements\Entry;
use yii\base\Event;

Event::on(
    RedirectMatcher::class,
    RedirectMatcher::EVENT_REGISTER_REDIRECT_TYPES,
    static function (RegisterRedirectTypesEvent $event): void {
        $event->types[] = new class implements CustomRedirectMatcherInterface {
            public function handle(): string
            {
                return 'legacy-id';
            }
            public function label(): string
            {
                return 'Legacy article ID (oldArticleId field)';
            }
            public function match(string $pattern, string $uri): ?array
            {
                if (!preg_match('#^/' . preg_quote($pattern, '#') . '/(\d+)$#', $uri, $m)) {
                    return null;
                }
                $entry = Entry::find()->oldArticleId((int) $m[1])->one();
                return $entry?->uri !== null
                    ? ['captures' => ['$1' => '/' . $entry->uri], 'query' => '']
                    : null;
            }
        };
    },
);
```

Store a rule with `type = 'legacy-id'`, `sourceUri = 'article'`, and
`targetUri = '$1'`; `/article/4291` then resolves to whatever entry has
`oldArticleId = 4291`.

## Tracking provider extensibility

See [tracking-provider-cookbook.md](tracking-provider-cookbook.md) for the dedicated walkthrough.
