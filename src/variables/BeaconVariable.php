<?php

namespace anvildev\beacon\variables;

use anvildev\beacon\elements\AuthorElement;
use anvildev\beacon\elements\db\AuthorQuery;
use anvildev\beacon\events\AfterResolveMetaEvent;
use anvildev\beacon\events\DefineMetaEvent;
use anvildev\beacon\events\DefineMetaTagsEvent;
use anvildev\beacon\events\DefineSchemasEvent;
use anvildev\beacon\helpers\AiUsagePolicy;
use anvildev\beacon\helpers\Assets;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\helpers\Ids;
use anvildev\beacon\helpers\SeoFieldReader;
use anvildev\beacon\helpers\SocialPlatforms;
use anvildev\beacon\models\Schema;
use anvildev\beacon\models\SchemaBundle;
use anvildev\beacon\models\SchemaGraphHolder;
use anvildev\beacon\models\SeoMeta;
use anvildev\beacon\Plugin;
use Craft;
use craft\base\Element;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\helpers\Json;
use craft\helpers\Template;
use craft\helpers\UrlHelper;
use craft\web\Request as WebRequest;
use Twig\Markup;
use yii\base\Event;
use yii\base\Request as BaseRequest;

/**
 * @phpstan-import-type BreadcrumbItem from \anvildev\beacon\types\ArrayShapes
 * @phpstan-import-type BreadcrumbItemInput from \anvildev\beacon\types\ArrayShapes
 * @phpstan-import-type MetaTag from \anvildev\beacon\models\SeoMeta
 * @phpstan-import-type HreflangAlternate from \anvildev\beacon\models\SeoMeta
 */
class BeaconVariable
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;

    private ?SeoMeta $cachedMeta = null;
    /** @var list<array<string,mixed>>|null */
    private ?array $cachedSchemas = null;
    /** @var array<string,mixed> */
    private array $overrides = [];
    /** @var list<array<string,mixed>> */
    private array $oneOffSchemas = [];
    /** @var array<string,MetaTag|null> */
    private array $tagOverrides = [];

    /** @var array<string,mixed>|null */
    private ?array $paginationState = null;
    private string $metaCacheStatus = 'n/a';
    private string $schemaCacheStatus = 'n/a';

    private ?LinksVariable $links = null;

    /**
     * Internal-link helpers, exposed at `craft.beacon.links.*`
     * (suggestionsFor, inboundLinks, outboundLinks, outboundLinksByType,
     * interactionStatus).
     */
    public function getLinks(): LinksVariable
    {
        return $this->links ??= new LinksVariable();
    }

    public function head(): Markup
    {
        // head() runs inside the page <head>; a throw in any sub-step (a meta-tag
        // event listener, JSON-LD encoding, a malformed schema) must not abort
        // template rendering with a half-written <head>. Degrade to a minimal
        // valid <title> and log instead.
        try {
            return $this->renderHead();
        } catch (\Throwable $e) {
            Craft::warning('Beacon: head() render failed, degrading to minimal <head>: ' . $e->getMessage(), 'beacon');
            return new Markup($this->minimalHeadFallback(), 'UTF-8');
        }
    }

    private function minimalHeadFallback(): string
    {
        try {
            $app = Craft::$app;
            if ($app?->getRequest() instanceof WebRequest) {
                $entry = $app->getUrlManager()->getMatchedElement();
                if ($entry instanceof Entry && (string) $entry->title !== '') {
                    return sprintf("<title>%s</title>\n", htmlspecialchars((string) $entry->title, ENT_QUOTES));
                }
            }
        } catch (\Throwable) {
            // Fall through to an empty fragment — never throw from the fallback.
        }
        return '';
    }

    private function renderHead(): Markup
    {
        $debugTimings = $this->serverTimingEnabled();
        $tStart = $debugTimings ? hrtime(true) : 0;
        $meta = $this->resolveMeta();
        $tMeta = $debugTimings ? hrtime(true) : 0;
        $schemas = $this->resolveSchemas();
        $tSchemas = $debugTimings ? hrtime(true) : 0;

        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES);

        $html = sprintf("<title>%s</title>\n", $h($meta->title));
        if ($meta->canonical) {
            $html .= sprintf('<link rel="canonical" href="%s">' . "\n", $h($meta->canonical));
        }
        $markdownAlternate = $this->resolveMarkdownAlternateUrl($meta);
        if ($markdownAlternate !== null) {
            $html .= sprintf('<link rel="alternate" type="text/markdown" href="%s">' . "\n", $h($markdownAlternate));
        }
        foreach ($meta->alternates as $alternate) {
            $html .= sprintf(
                '<link rel="alternate" hreflang="%s" href="%s">' . "\n",
                $h($alternate['hreflang']),
                $h($alternate['href']),
            );
        }
        foreach ($meta->paginationLinkTags as $link) {
            $html .= sprintf('<link rel="%s" href="%s">' . "\n", $h($link['rel']), $h($link['href']));
        }

        $tags = $this->applyTagOverrides($this->buildMetaTagMap($meta));
        $app = Craft::$app;
        if ($app?->getRequest() instanceof WebRequest) {
            $entry = $app->getUrlManager()->getMatchedElement();
            Event::trigger(
                Plugin::class,
                Plugin::EVENT_DEFINE_META_TAGS,
                new DefineMetaTagsEvent($tags, $meta, $entry instanceof Entry ? $entry : null, $app->getRequest()),
            );
        }
        $html .= $this->renderMetaTagMap($tags);
        // Repeatable auto-derived tags (og:locale:alternate, article:author) that
        // can't be keyed by name; rendered with the same skip-empty loop.
        $html .= $this->renderMetaTagMap($meta->extraMetaTags);
        $html .= $this->renderAiUsageMetaTags($meta);

        if (!empty($schemas)) {
            $payload = count($schemas) === 1 ? $schemas[0] : $schemas;
            $html .= '<script type="application/ld+json">' . Json::encode($payload, self::JSON_FLAGS) . '</script>' . "\n";
        }
        $html .= $this->renderTrackingPlacement('head');
        $tTracking = $debugTimings ? hrtime(true) : 0;

        $html .= $this->renderBreadcrumbJsonLd();

        $this->applySeoHeaders($meta);
        if ($debugTimings) {
            $this->emitServerTiming([
                'resolve' => $tMeta - $tStart,
                'schema' => $tSchemas - $tMeta,
                'tracking' => $tTracking - $tSchemas,
                'total' => hrtime(true) - $tStart,
            ]);
        }

        return new Markup($html, 'UTF-8');
    }

    /**
     * Server-Timing is emitted only when an operator has opted in via
     * `BEACON_META_DEBUG=1` or Craft's devMode — diagnostics live on their
     * own switch, separate from the always-on SEO headers.
     */
    private function serverTimingEnabled(): bool
    {
        return Craft::$app !== null
            && (getenv('BEACON_META_DEBUG') === '1' || Craft::$app->getConfig()->getGeneral()->devMode);
    }

    /**
     * @param array<string, float|int> $nanos hrtime() deltas in nanoseconds per phase
     */
    private function emitServerTiming(array $nanos): void
    {
        if (Craft::$app === null) {
            return;
        }
        $parts = array_map(
            static fn(string $phase, float|int $delta): string =>
                sprintf('beacon-%s;dur=%.2f', $phase, max(0.0, (float) $delta / 1_000_000.0)),
            array_keys($nanos),
            $nanos,
        );
        if ($parts === []) {
            return;
        }
        $headers = Http::response()->getHeaders();
        $existing = $headers->get('Server-Timing');
        $new = implode(', ', $parts);
        $headers->set('Server-Timing', is_string($existing) && $existing !== '' ? $existing . ', ' . $new : $new);
    }

    /**
     * Compute the `<link rel="alternate" type="text/markdown">` URL for the
     * current entry, or null when the discovery tag should be suppressed.
     *
     * Source URL is, in order:
     *  1. `$meta->canonical` when explicitly set on the entry's SEO field
     *  2. the entry's own public URL (`$element->getUrl()`)
     *
     * Suppressed when: the feature is off, the entry is noindex, the matched
     * element isn't an Entry, or neither URL source resolves.
     */
    private function resolveMarkdownAlternateUrl(SeoMeta $meta): ?string
    {
        if (Plugin::$plugin === null || Craft::$app === null) {
            return null;
        }
        $settings = Plugin::$plugin->settings->get();
        if (!$settings->geoMarkdownEnabled || !$settings->geoMarkdownMdSuffixEnabled) {
            return null;
        }
        if (in_array('noindex', $meta->robots, true)) {
            return null;
        }
        $element = Craft::$app->getUrlManager()->getMatchedElement();
        if (!$element instanceof Entry) {
            return null;
        }
        if (self::isHomepageEntryUri(is_string($element->uri) ? $element->uri : '')) {
            return null;
        }

        $sourceUrl = (is_string($meta->canonical) && $meta->canonical !== '')
            ? $meta->canonical
            : $element->getUrl();
        if (!is_string($sourceUrl) || $sourceUrl === '') {
            return null;
        }

        $base = rtrim($sourceUrl, '/');
        $qPos = strpos($base, '?');
        return $qPos === false
            ? $base . '.md'
            : substr($base, 0, $qPos) . '.md' . substr($base, $qPos);
    }

    /**
     * @return array<string,mixed>
     */
    public function debug(): array
    {
        $meta = $this->resolveMeta();
        $schemas = $this->resolveSchemas();

        $route = null;
        if (Craft::$app !== null) {
            $entry = Craft::$app->getUrlManager()->getMatchedElement();
            $route = $entry instanceof Element
                ? $entry->getUrl()
                : ($this->currentWebRequest()?->getPathInfo());
        }

        return [
            'route' => $route,
            'tagCount' => $this->countRenderedTags($meta),
            'schemaCount' => count($schemas),
            'metaCache' => $this->metaCacheStatus,
            'schemaCache' => $this->schemaCacheStatus,
            'sourceMap' => $meta->sourceMap,
            'robots' => $meta->robots,
            'tags' => $this->tags(),
        ];
    }

    /**
     * @return array<string,MetaTag>
     */
    public function tags(): array
    {
        return $this->applyTagOverrides($this->buildMetaTagMap($this->resolveMeta()));
    }

    public function setTag(string $name, string|int|float|bool|\Stringable|null $content): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }
        $attr = str_starts_with($name, 'og:') || str_starts_with($name, 'article:') ? 'property' : 'name';
        $this->tagOverrides[$name] = ['attr' => $attr, 'name' => $name, 'content' => trim((string) $content)];
    }

    public function removeTag(string $name): void
    {
        $name = trim($name);
        if ($name !== '') {
            $this->tagOverrides[$name] = null;
        }
    }

    /**
     * Override the auto-derived breadcrumb chain for the current request.
     * Call BEFORE craft.beacon.head() in the layout/section template.
     *
     * @param array<int, BreadcrumbItemInput> $items
     */
    public function setBreadcrumbs(array $items): void
    {
        Plugin::getInstance()->breadcrumbs->setOverride($items);
    }

    /**
     * @return array<int, BreadcrumbItem>
     */
    public function breadcrumbs(): array
    {
        if (Craft::$app === null) {
            return [];
        }
        $plugin = Plugin::getInstance();
        $site = Craft::$app->getSites()->getCurrentSite();
        return $plugin->breadcrumbs->getResolved(
            $this->currentEntry(),
            $plugin->siteSettings->getBreadcrumbs($site->id),
            $site->getBaseUrl() ?? '/',
        );
    }

    private function currentEntry(): ?Entry
    {
        $element = Craft::$app->getUrlManager()->getMatchedElement();
        return $element instanceof Entry ? $element : null;
    }

    /** Returns the current web request, or null when running in console/CP context. */
    private function currentWebRequest(): ?WebRequest
    {
        $request = Craft::$app?->getRequest();
        return $request instanceof WebRequest ? $request : null;
    }

    private function renderBreadcrumbJsonLd(): string
    {
        if (Craft::$app === null) {
            return '';
        }
        $jsonLd = Plugin::getInstance()->breadcrumbs->asJsonLd($this->breadcrumbs());
        return $jsonLd === null
            ? ''
            : "\n<script type=\"application/ld+json\">" . Json::encode($jsonLd, self::JSON_FLAGS) . '</script>';
    }

    /**
     * Returns the rendered tracking-script HTML for the given placement, or
     * an empty string when the plugin/Craft isn't fully booted (e.g. inside
     * targeted unit tests of {@see self::head()} that bypass Craft init).
     */
    private function renderTrackingPlacement(string $placement): string
    {
        if (Craft::$app === null) {
            return '';
        }
        $request = Craft::$app->getRequest();
        if ($request->getIsCpRequest() || $request->getIsConsoleRequest() || $request->getIsPreview()) {
            return '';
        }
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return '';
        }
        return $plugin->tracking->renderPlacement(Craft::$app->getSites()->getCurrentSite()->id, $placement);
    }

    public function bodyStart(): Markup
    {
        return Template::raw($this->renderTrackingPlacement('bodyStart'));
    }

    public function bodyEnd(): Markup
    {
        return Template::raw($this->renderTrackingPlacement('bodyEnd'));
    }

    /**
     * @return list<array{platform:string, url:string, handle:?string, label:string}>
     */
    public function socials(): array
    {
        if (Plugin::$plugin === null) {
            return [];
        }
        $profiles = Plugin::$plugin->settings->get()->socialProfiles;
        $rows = [];
        foreach (SocialPlatforms::all() as $platform) {
            $url = $profiles[$platform['key']] ?? null;
            if (!is_string($url) || ($trimmed = trim($url)) === '') {
                continue;
            }
            $rows[] = [
                'platform' => $platform['key'],
                'url' => $trimmed,
                'handle' => SocialPlatforms::parseHandle($platform['key'], $url),
                'label' => $platform['label'],
            ];
        }
        return $rows;
    }

    public function socialUrl(string $platform): ?string
    {
        if (Plugin::$plugin === null) {
            return null;
        }
        $url = Plugin::$plugin->settings->get()->socialProfiles[$platform] ?? null;
        if (!is_string($url) || ($trimmed = trim($url)) === '') {
            return null;
        }
        return $trimmed;
    }

    public function authors(): AuthorQuery
    {
        return AuthorElement::find();
    }

    public function trackingFor(string $placement, ?string $env = null): Markup
    {
        if (Craft::$app === null) {
            return Template::raw('');
        }
        $request = Craft::$app->getRequest();
        if ($request->getIsCpRequest() || $request->getIsConsoleRequest() || $request->getIsPreview()) {
            return Template::raw('');
        }
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $tracking = Plugin::getInstance()->tracking;
        return Template::raw($env === null || trim($env) === ''
            ? $tracking->renderPlacement($siteId, $placement)
            : $tracking->renderPlacementWithEnv($siteId, $placement, $env));
    }

    public function getMeta(): SeoMeta
    {
        return $this->resolveMeta();
    }

    /**
     * Ergonomic Twig alias for {@see self::getMeta()}.
     */
    public function meta(): SeoMeta
    {
        return $this->resolveMeta();
    }

    /**
     * Optional listing pagination overrides. Call early in templates.
     *
     * Typical keys: `page` (int ≥1), `pageCount`, `baseUrl`, `pageParam`,
     * `canonicalMode` (`firstPageCanonical` | `self`), `appendPageToTitle` (bool).
     *
     * @param array<string,mixed> $config
     */
    public function setPagination(array $config): void
    {
        $this->paginationState = $config;
        $this->cachedMeta = null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->overrides[$key] = $value;
        $this->cachedMeta = null;
    }

    /**
     * @param array<string,mixed> $schema
     */
    public function addSchema(array $schema): void
    {
        $this->oneOffSchemas[] = $schema;
    }

    /**
     * Detects entry URIs that resolve to the site root. The Yii `<uri:.+>.md`
     * route needs at least one URI character before `.md`; appending `.md` to
     * `https://example.com/` produces `https://example.com.md` — a different
     * domain entirely. Returns true for empty, `/`, or `'__home__'`.
     */
    public static function isHomepageEntryUri(string $uri): bool
    {
        return trim($uri, '/') === '' || $uri === '__home__';
    }

    /**
     * Rewrites hreflang alternate URLs to point at page-N when the
     * `canonicalMode` argument to `setPagination()` is `'self'`.
     *
     * @param list<HreflangAlternate> $alternates
     * @return list<HreflangAlternate>
     */
    public static function pageAlternates(array $alternates, string $pageParam, int $page): array
    {
        if ($page <= 1) {
            return $alternates;
        }
        return array_map(
            static fn(array $alt): array => [
                'hreflang' => $alt['hreflang'],
                'href' => UrlHelper::urlWithParams($alt['href'], [$pageParam => $page]),
            ],
            $alternates,
        );
    }

    /**
     * Wrong-type values (e.g. set('robots', 'noindex') where robots is list<string>)
     * raise TypeError; swallow + warn so the override is dropped rather than crashing head().
     *
     * @param array<string,mixed> $overrides
     */
    private function applyOverrides(SeoMeta $meta, array $overrides): void
    {
        foreach ($overrides as $key => $value) {
            if (!property_exists($meta, $key)) {
                continue;
            }
            try {
                $meta->{$key} = $value;
            } catch (\TypeError $e) {
                Craft::warning(
                    sprintf(
                        'Beacon: ignored craft.beacon.set(%s, ...) — value type does not match SeoMeta::$%s (%s)',
                        $key,
                        $key,
                        $e->getMessage(),
                    ),
                    'beacon',
                );
            }
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function schemas(): array
    {
        return $this->resolveSchemas();
    }

    private function resolveMeta(): SeoMeta
    {
        if ($this->cachedMeta !== null) {
            $this->metaCacheStatus = 'request-cache-hit';
            return $this->cachedMeta;
        }

        if (Craft::$app !== null && Plugin::$plugin !== null) {
            $cacheSeconds = Plugin::$plugin->settings->get()->metaCacheDuration;
            $request = Craft::$app->getRequest();
            if (
                $cacheSeconds !== null
                && $cacheSeconds > 0
                && $request instanceof WebRequest
                && $request->getIsSiteRequest()
                && !$request->getIsPreview()
                && $this->overrides === []
                && $this->paginationState === null
            ) {
                $entry = Craft::$app->getUrlManager()->getMatchedElement();
                if ($entry instanceof Element) {
                    $cacheKey = 'beacon.meta.' . $entry->id . '.' . $entry->siteId . '.' . ($entry->dateUpdated?->format('U') ?? '0');
                    $cache = Craft::$app->getCache();
                    $cached = $cache->get($cacheKey);
                    if ($cached instanceof SeoMeta) {
                        $this->metaCacheStatus = 'cross-request-hit';
                        return $this->cachedMeta = $cached;
                    }
                    $meta = $this->resolveMetaUncached();
                    $this->metaCacheStatus = 'cross-request-miss';
                    $cache->set($cacheKey, clone $meta, $cacheSeconds);
                    return $this->cachedMeta = $meta;
                }
            }
        }

        $this->metaCacheStatus = 'disabled';
        return $this->cachedMeta = $this->resolveMetaUncached();
    }

    private function resolveMetaUncached(): SeoMeta
    {
        if ($this->cachedMeta !== null) {
            return $this->cachedMeta;
        }

        $plugin = Plugin::$plugin;
        $app = Craft::$app;
        $entry = $app->getUrlManager()->getMatchedElement();
        $element = $entry instanceof Element ? $entry : null;
        $requestEntry = $entry instanceof Entry ? $entry : null;

        $fieldValue = $this->extractSeoFieldValue($element);
        $entryTitle = $element !== null ? (string) $element->title : '';
        $siteName = $app->getSites()->getCurrentSite()->name;
        $geoDefaults = $plugin->settings->getGeoDefaults();
        $entryUrl = $element?->getUrl();

        $bundleSchemaTypes = [];
        if ($requestEntry !== null) {
            $typeHandle = $requestEntry->getType()->handle ?? null;
            if (is_string($typeHandle) && $typeHandle !== '') {
                foreach ($plugin->bundles->getSchemasForEntryType($typeHandle) as $schemaRow) {
                    $bundleSchemaTypes[] = $schemaRow->schemaType;
                }
            }
        }

        $meta = $plugin->metaResolver->resolve(
            $fieldValue,
            $entryTitle,
            $siteName,
            $geoDefaults,
            $entryUrl,
            $requestEntry,
            $bundleSchemaTypes,
        );

        $this->applyOverrides($meta, $this->overrides);
        $this->applyPaginationToMeta($meta);

        $request = $app->getRequest();
        if ($request instanceof WebRequest) {
            Event::trigger(Plugin::class, Plugin::EVENT_DEFINE_META, new DefineMetaEvent($meta, $requestEntry, $request));
            Event::trigger(
                Plugin::class,
                Plugin::EVENT_AFTER_RESOLVE_META,
                new AfterResolveMetaEvent(clone $meta, $requestEntry, $request),
            );
            if ($this->logMetaDebug()) {
                Craft::info('[beacon/meta] resolved entryId=' . ($requestEntry?->id ?? '-') . ' robots=' . implode(',', $meta->robots), 'beacon');
            }
        }

        return $meta;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function resolveSchemas(): array
    {
        if (Craft::$app === null) {
            $this->schemaCacheStatus = 'disabled';
            return [...($this->cachedSchemas ?? []), ...$this->oneOffSchemas];
        }

        $entry = Craft::$app->getUrlManager()->getMatchedElement();
        $entryResolved = $entry instanceof Entry ? $entry : null;
        $request = Craft::$app->getRequest();

        if ($this->cachedSchemas !== null) {
            $this->schemaCacheStatus = 'hit';
            return $this->appendIdentityAndFinalize(
                [...$this->cachedSchemas, ...$this->oneOffSchemas],
                $entryResolved,
                $request,
            );
        }

        $this->schemaCacheStatus = 'miss';
        $rendered = $this->renderSchemasForEntry($entry instanceof Element ? $entry : null, $entryResolved);
        $this->cachedSchemas = $rendered;
        return $this->appendIdentityAndFinalize(
            [...$rendered, ...$this->oneOffSchemas],
            $entryResolved,
            $request,
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function renderSchemasForEntry(?Element $entry, ?Entry $entryResolved): array
    {
        if (!$entry instanceof Element) {
            return [];
        }
        $entryTypeHandle = method_exists($entry, 'getType') ? ($entry->getType()->handle ?? null) : null;
        if ($entryTypeHandle === null) {
            return $this->renderEntryAddonsOnly($entryResolved);
        }
        $plugin = Plugin::$plugin;
        $schemasCfg = $plugin->bundles->getSchemasForEntryType($entryTypeHandle);
        if (empty($schemasCfg)) {
            return $this->renderEntryAddonsOnly($entryResolved);
        }

        $fieldValue = $this->extractSeoFieldValue($entry);
        $addons = $fieldValue['schemaAddons'] ?? [];
        return $plugin->schema->render(
            $this->buildAdHocBundle($schemasCfg),
            is_array($addons) ? $addons : [],
            $plugin->schemaContext->build($entry, $fieldValue),
        );
    }

    /**
     * @param list<array<string,mixed>> $base
     * @return list<array<string,mixed>>
     */
    private function appendIdentityAndFinalize(array $base, ?Entry $entryResolved, BaseRequest $request): array
    {
        if (($identity = $this->buildIdentitySchemaNode()) !== null) {
            $base[] = $identity;
        }
        if (($geo = $this->buildGeoProvenanceSchemaNode($entryResolved)) !== null) {
            $base[] = $geo;
        }
        return $this->finalizeSchemaGraph($base, $entryResolved, $request);
    }

    /**
     * @param list<array<string,mixed>> $combined
     * @return list<array<string, mixed>>
     */
    private function finalizeSchemaGraph(array $combined, ?Entry $entryResolved, BaseRequest $request): array
    {
        $holder = new SchemaGraphHolder($combined);
        if ($request instanceof WebRequest) {
            Event::trigger(
                Plugin::class,
                Plugin::EVENT_DEFINE_SCHEMAS,
                new DefineSchemasEvent($holder, $entryResolved, $request),
            );
        }
        return $holder->nodes;
    }

    private function applyPaginationToMeta(SeoMeta $meta): void
    {
        $meta->paginationLinkTags = [];
        if ($this->paginationState === null) {
            return;
        }

        /** @var array<string,mixed> $state */
        $state = $this->paginationState + [
            'page' => 1,
            'pageCount' => null,
            'baseUrl' => '',
            'pageParam' => 'page',
            'canonicalMode' => 'firstPageCanonical',
            'appendPageToTitle' => false,
        ];

        $page = max(1, (int) ($state['page'] ?? 1));
        $pageParam = trim((string) ($state['pageParam'] ?: 'page')) ?: 'page';

        $pageCountRaw = $state['pageCount'] ?? null;
        $pageCount = ($pageCountRaw !== null && is_numeric($pageCountRaw)) ? max(1, (int) $pageCountRaw) : null;

        $baseUrl = trim((string) ($state['baseUrl'] ?? ''));
        if ($baseUrl === '') {
            return;
        }

        $canonicalMode = (string) ($state['canonicalMode'] ?? 'firstPageCanonical');
        $appendPageToTitle = (bool) ($state['appendPageToTitle'] ?? false);

        $pageSelf = $page <= 1 ? $baseUrl : UrlHelper::urlWithParams($baseUrl, [$pageParam => $page]);
        $canonicalChosen = $canonicalMode === 'self' ? $pageSelf : $baseUrl;

        $meta->canonical = $canonicalChosen;
        $meta->openGraph['url'] = $canonicalChosen;

        if ($canonicalMode === 'self' && $page > 1) {
            $meta->alternates = self::pageAlternates($meta->alternates, $pageParam, $page);
        }

        if ($appendPageToTitle && $page > 1) {
            $pageSuffixLabel = Craft::t('beacon', 'variable.page') . ' ' . $page;
            $meta->title = trim($meta->title) === '' ? $pageSuffixLabel : ($meta->title . ' — ' . $pageSuffixLabel);
            $meta->openGraph['title'] = isset($meta->openGraph['title']) && (string) $meta->openGraph['title'] !== ''
                ? ($meta->openGraph['title'] . ' — ' . $pageSuffixLabel)
                : $meta->title;
            $meta->twitter['title'] = isset($meta->twitter['title']) && (string) $meta->twitter['title'] !== ''
                ? ($meta->twitter['title'] . ' — ' . $pageSuffixLabel)
                : $meta->title;
        }

        if ($page > 1) {
            $meta->paginationLinkTags[] = ['rel' => 'prev', 'href' => UrlHelper::urlWithParams($baseUrl, [$pageParam => $page - 1])];
        }
        if ($pageCount !== null && $page < $pageCount) {
            $meta->paginationLinkTags[] = ['rel' => 'next', 'href' => UrlHelper::urlWithParams($baseUrl, [$pageParam => $page + 1])];
        }
    }

    private function logMetaDebug(): bool
    {
        if (getenv('BEACON_META_DEBUG') === '1') {
            return true;
        }
        $request = Craft::$app->getRequest();
        return $request instanceof WebRequest
            && $request->getIsSiteRequest()
            && Craft::$app->getConfig()->general->devMode;
    }

    /**
     * @param list<Schema> $schemas
     */
    private function buildAdHocBundle(array $schemas): SchemaBundle
    {
        $bundle = new SchemaBundle();
        $bundle->entryTypeHandle = $schemas[0]->entryTypeHandle ?? '';
        $bundle->schemas = array_map(
            static fn(Schema $s) => array_filter(
                ['type' => $s->schemaType, 'mapping' => $s->mapping],
                static fn($v) => $v !== null,
            ),
            $schemas,
        );
        return $bundle;
    }

    /**
     * Without this entry-only path, per-entry add-ons authored via the SEO
     * field's modal would be silently dropped when the entry type lacks a
     * handle or has no `beacon_schema` row.
     *
     * @return list<array<string,mixed>>
     */
    private function renderEntryAddonsOnly(?Entry $entry): array
    {
        if ($entry === null || Plugin::$plugin === null) {
            return [];
        }
        $fieldValue = $this->extractSeoFieldValue($entry);
        $addons = $fieldValue['schemaAddons'] ?? [];
        if (!is_array($addons) || $addons === []) {
            return [];
        }
        $emptyBundle = new SchemaBundle();
        $emptyBundle->entryTypeHandle = '';
        $emptyBundle->schemas = [];
        return Plugin::$plugin->schema->render(
            $emptyBundle,
            $addons,
            Plugin::$plugin->schemaContext->build($entry, $fieldValue),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function extractSeoFieldValue(?Element $entry): array
    {
        return $entry === null ? [] : (SeoFieldReader::readValueFor($entry) ?? []);
    }

    /**
     * @return array<string,MetaTag>
     */
    private function buildMetaTagMap(SeoMeta $meta): array
    {
        $tags = [];
        if ($meta->description !== '') {
            $tags['description'] = ['attr' => 'name', 'name' => 'description', 'content' => $meta->description];
        }
        if (!empty($meta->robots)) {
            $tags['robots'] = ['attr' => 'name', 'name' => 'robots', 'content' => implode(', ', $meta->robots)];
        }
        // Each row: [source-array, key→tag-name map, attr type]
        $isArticle = ($meta->openGraph['type'] ?? '') === 'article';
        $groups = [
            [$meta->openGraph, [
                'title' => 'og:title',
                'description' => 'og:description',
                'type' => 'og:type',
                'siteName' => 'og:site_name',
                'url' => 'og:url',
                'image' => 'og:image',
                'imageWidth' => 'og:image:width',
                'imageHeight' => 'og:image:height',
                'imageAlt' => 'og:image:alt',
                'locale' => 'og:locale',
            ], 'property'],
            [$meta->twitter, [
                'card' => 'twitter:card',
                'title' => 'twitter:title',
                'description' => 'twitter:description',
                'image' => 'twitter:image',
                'site' => 'twitter:site',
                'creator' => 'twitter:creator',
            ], 'name'],
        ];
        if ($isArticle && !empty($meta->articleTimes)) {
            $groups[] = [$meta->articleTimes, [
                'publishedTime' => 'article:published_time',
                'modifiedTime' => 'article:modified_time',
            ], 'property'];
        }
        foreach ($groups as [$source, $map, $attr]) {
            foreach ($map as $key => $name) {
                $value = $source[$key] ?? null;
                if (is_scalar($value) && (string) $value !== '') {
                    $tags[$name] = ['attr' => $attr, 'name' => $name, 'content' => (string) $value];
                }
            }
        }
        return $tags;
    }

    /**
     * @param array<string,MetaTag> $tags
     * @return array<string,MetaTag>
     */
    private function applyTagOverrides(array $tags): array
    {
        foreach ($this->tagOverrides as $name => $row) {
            if ($row === null) {
                unset($tags[$name]);
            } else {
                $tags[$name] = $row;
            }
        }
        return $tags;
    }

    /**
     * @param array<array-key,MetaTag> $tags name-keyed map or a plain list (repeatable tags)
     */
    private function renderMetaTagMap(array $tags): string
    {
        $html = '';
        foreach ($tags as $row) {
            // Skip empty-content tags so a setTag() override with a blank/falsy
            // value emits nothing, matching buildMetaTagMap()'s skip-empty rule.
            if ($row['content'] === '') {
                continue;
            }
            $html .= sprintf(
                '<meta %s="%s" content="%s">' . "\n",
                htmlspecialchars($row['attr'], ENT_QUOTES),
                htmlspecialchars($row['name'], ENT_QUOTES),
                htmlspecialchars($row['content'], ENT_QUOTES),
            );
        }
        return $html;
    }

    /**
     * TDMRep meta tags for the resolved AI-usage policy. `tdm-reservation: 1`
     * reserves text-and-data-mining rights; `tdm-policy` points at the
     * operator's published policy when configured. Emits nothing for `allow`.
     */
    private function renderAiUsageMetaTags(SeoMeta $meta): string
    {
        if (!AiUsagePolicy::isRestrictive($meta->aiUsagePolicy)) {
            return '';
        }
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES);
        $html = sprintf(
            '<meta name="tdm-reservation" content="%d">' . "\n",
            AiUsagePolicy::tdmReservation($meta->aiUsagePolicy),
        );
        $policyUrl = Plugin::$plugin?->settings->get()->aiUsagePolicyUrl;
        if (is_string($policyUrl) && trim($policyUrl) !== '') {
            $html .= sprintf('<meta name="tdm-policy" content="%s">' . "\n", $h(trim($policyUrl)));
        }
        return $html;
    }

    private function applySeoHeaders(SeoMeta $meta): void
    {
        if (Craft::$app === null || Plugin::$plugin === null) {
            return;
        }
        $headers = Http::response()->getHeaders();
        if (!empty($meta->robots)) {
            $headers->set('X-Robots-Tag', implode(', ', $meta->robots));
        }
        $contentUsage = AiUsagePolicy::contentUsage($meta->aiUsagePolicy);
        if ($contentUsage !== null) {
            $headers->set('Content-Usage', $contentUsage);
        }
        if (is_string($meta->canonical) && $meta->canonical !== '') {
            // Strip CR/LF/NUL so a stray newline in the canonical can't break the
            // header (PHP's header() rejects such values outright) or be abused
            // for header injection. Defense-in-depth on editor-supplied input.
            $canonical = str_replace(["\r", "\n", "\0"], '', $meta->canonical);
            $headers->set('Link', '<' . $canonical . '>; rel="canonical"');
        }
    }

    private function countRenderedTags(SeoMeta $meta): int
    {
        $n = ($meta->title !== '' ? 1 : 0)
            + ($meta->description !== '' ? 1 : 0)
            + ($meta->canonical !== null && $meta->canonical !== '' ? 1 : 0)
            + (!empty($meta->robots) ? 1 : 0)
            + count($meta->alternates)
            + count($meta->paginationLinkTags);
        foreach ([$meta->openGraph, $meta->twitter] as $group) {
            foreach ($group as $value) {
                if (is_scalar($value) && $value !== '') {
                    $n++;
                }
            }
        }
        if (($meta->openGraph['type'] ?? '') === 'article' && !empty($meta->articleTimes)) {
            $n += count($meta->articleTimes);
        }
        return $n;
    }

    /**
     * Builds a global identity JSON-LD node (Organization/Person) from
     * Beacon settings. Returns null when no identity name is configured.
     *
     * @return array<string,mixed>|null
     */
    private function buildIdentitySchemaNode(): ?array
    {
        if (Craft::$app === null || Plugin::$plugin === null) {
            return null;
        }

        $settings = Plugin::$plugin->settings->get();
        $site = Craft::$app->getSites()->getCurrentSite();
        $explicit = trim((string) ($settings->organizationName ?? ''));
        $name = $explicit !== '' ? $explicit : trim((string) $site->name);
        if ($name === '') {
            return null;
        }

        $type = \anvildev\beacon\helpers\IdentityTypes::normalize($settings->identityType);

        $identity = [
            '@context' => 'https://schema.org',
            '@type' => $type,
            'name' => $name,
        ];

        $siteUrl = $site->getBaseUrl();
        if (is_string($siteUrl) && trim($siteUrl) !== '') {
            $base = rtrim($siteUrl, '/');
            // Stable @id (mirrors SchemamapService's identity node) so the
            // identity is referenceable and dedupes across the graph + schemamap.
            $identity['@id'] = $base . '/#identity';
            $identity['url'] = $base;
        }

        if (($sameAs = $settings->sameAsUrls()) !== []) {
            $identity['sameAs'] = $sameAs;
        }

        if ($settings->organizationLogoAssetId !== null) {
            $asset = Assets::findById((int) $settings->organizationLogoAssetId);
            // Mirror the image branch below: a volume without a public base URL
            // returns null, which would emit `"logo": null` (invalid JSON-LD).
            if ($asset instanceof Asset && is_string($logoUrl = $asset->getUrl()) && $logoUrl !== '') {
                $identity['logo'] = $logoUrl;
            }
        }

        if ($settings->organizationImageAssetId !== null) {
            $imageAsset = Assets::findById((int) $settings->organizationImageAssetId);
            if ($imageAsset instanceof Asset && is_string($imgUrl = $imageAsset->getUrl()) && $imgUrl !== '') {
                $identity['image'] = $imgUrl;
            }
        }

        $advanced = $settings->identityAdvanced;
        $adv = static function(string $k) use ($advanced): string {
            $val = $advanced[$k] ?? null;
            return is_string($val) ? trim($val) : '';
        };

        foreach ([
            'alternateName', 'legalName', 'description', 'email', 'telephone',
            'foundingDate', 'foundingLocation', 'jobTitle', 'birthPlace',
            'givenName', 'familyName', 'taxID', 'naics', 'duns', 'iso6523Code',
        ] as $key) {
            if (($value = $adv($key)) !== '') {
                $identity[$key] = $value;
            }
        }

        $address = array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $adv('streetAddress'),
            'addressLocality' => $adv('addressLocality'),
            'addressRegion' => $adv('addressRegion'),
            'postalCode' => $adv('postalCode'),
            'addressCountry' => $adv('addressCountry'),
        ], static fn(string $v): bool => $v !== '');
        if (count($address) > 1) {
            $identity['address'] = $address;
        }

        $lat = $adv('geoLatitude');
        $lng = $adv('geoLongitude');
        if ($lat !== '' && $lng !== '') {
            $identity['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $lat, 'longitude' => $lng];
        }

        $contactType = $adv('contactType');
        $contactEmail = $adv('contactEmail');
        $contactTelephone = $adv('contactTelephone');
        if ($contactType !== '' || $contactEmail !== '' || $contactTelephone !== '') {
            $contactPoint = array_filter(
                ['@type' => 'ContactPoint', 'contactType' => $contactType, 'email' => $contactEmail, 'telephone' => $contactTelephone],
                static fn(string $v): bool => $v !== '',
            );
            $identity['contactPoint'] = [$contactPoint];
        }

        if ($type === 'Organization' && isset($advanced['founder']) && is_array($advanced['founder']) && $advanced['founder'] !== []) {
            $founders = array_values(array_filter(
                array_map(
                    static fn($n): ?array => ($name = trim((string) $n)) !== '' ? ['@type' => 'Person', 'name' => $name] : null,
                    $advanced['founder'],
                ),
            ));
            if ($founders !== []) {
                $identity['founder'] = $founders;
            }
        }

        foreach (['knowsAbout', 'knowsLanguage'] as $listKey) {
            if (isset($advanced[$listKey]) && is_array($advanced[$listKey]) && $advanced[$listKey] !== []) {
                $clean = array_values(array_filter(
                    array_map(static fn($v): string => trim((string) $v), $advanced[$listKey]),
                    static fn(string $v): bool => $v !== '',
                ));
                if ($clean !== []) {
                    $identity[$listKey] = $clean;
                }
            }
        }

        return $identity;
    }

    /**
     * Adds an explicit citation/provenance JSON-LD node for GEO consumers.
     *
     * @return array<string,mixed>|null
     */
    private function buildGeoProvenanceSchemaNode(?Entry $entry): ?array
    {
        if ($entry === null || Craft::$app === null || Plugin::$plugin === null) {
            return null;
        }
        $settings = Plugin::$plugin->settings->get();
        if (!$settings->geoProvenanceSchemaEnabled) {
            return null;
        }

        $canonical = $entry->getUrl();
        if (!is_string($canonical) || trim($canonical) === '') {
            return null;
        }
        $canonical = rtrim($canonical, '/');
        $site = Craft::$app->getSites()->getCurrentSite();
        $siteUrl = rtrim((string) ($site->getBaseUrl() ?? ''), '/');
        if ($siteUrl === '') {
            return null;
        }

        $sectionHandle = $entry->getSection()?->handle ?? 'content';
        $citations = [
            $canonical,
            $siteUrl . '/llms.txt',
            $siteUrl . '/llms-full.txt',
            $siteUrl . '/geo/export?id=' . (int) $entry->id,
        ];
        if ($settings->geoMarkdownMdSuffixEnabled && is_string($entry->uri) && $entry->uri !== '') {
            $citations[] = $siteUrl . '/' . ltrim($entry->uri, '/') . '.md';
        }
        $citations[] = $siteUrl . '/feed/' . $sectionHandle . '.json';
        $citations[] = $siteUrl . '/feed/' . $sectionHandle . '.atom';
        $citations = array_values(array_unique(array_filter($citations, static fn(string $u): bool => trim($u) !== '')));

        $llms = Plugin::$plugin->siteSettings->getLlms((int) $site->id);
        $licenseUrl = is_string($llms->licenseUrl) ? trim($llms->licenseUrl) : '';
        $policyUrl = is_string($llms->policyUrl) ? trim($llms->policyUrl) : '';
        $preferredAttribution = is_string($llms->preferredAttribution) ? trim($llms->preferredAttribution) : '';

        $node = [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            '@id' => $canonical . '#beacon-geo-provenance',
            'url' => $canonical,
            'name' => (string) $entry->title,
            'isPartOf' => ['@type' => 'WebSite', 'url' => $siteUrl, 'name' => $site->name],
            'dateModified' => $entry->dateUpdated?->format(DATE_ATOM),
            'citation' => $citations,
            'license' => $licenseUrl !== '' ? $licenseUrl : null,
            'conditionsOfAccess' => $policyUrl !== '' ? $policyUrl : null,
            'creditText' => $preferredAttribution !== '' ? $preferredAttribution : null,
        ];

        $explicitOrg = trim((string) ($settings->organizationName ?? ''));
        $orgName = $explicitOrg !== '' ? $explicitOrg : trim((string) $site->name);
        if ($orgName !== '') {
            $node['sdPublisher'] = ['@type' => 'Organization', 'name' => $orgName];
            $node['copyrightHolder'] = ['@type' => 'Organization', 'name' => $orgName];
        }

        $authors = $this->resolveGeoProvenanceAuthors($entry);
        if ($authors !== []) {
            $node['author'] = $authors;
        }

        return array_filter($node, static fn($v): bool => $v !== null && $v !== '');
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function resolveGeoProvenanceAuthors(Entry $entry): array
    {
        $ids = $this->extractSeoFieldValue($entry)['authorIds'] ?? [];
        if (!is_array($ids) || $ids === []) {
            return [];
        }
        $ids = Ids::positiveInts($ids);
        if ($ids === []) {
            return [];
        }

        /** @var list<AuthorElement> $authors */
        $authors = AuthorElement::find()
            ->id($ids)
            ->siteId((int) $entry->siteId)
            ->status(null)
            ->all();

        return array_values(array_filter(
            array_map(static fn(AuthorElement $a): ?array => $a->toPersonNode(), $authors),
        ));
    }
}
