<?php

namespace anvildev\beacon\services;

use anvildev\beacon\elements\AuthorElement;
use anvildev\beacon\enums\Environment;
use anvildev\beacon\helpers\AiUsagePolicy;
use anvildev\beacon\helpers\Assets;
use anvildev\beacon\helpers\Ids;
use anvildev\beacon\helpers\RobotsDirectives;
use anvildev\beacon\models\SeoMeta;
use anvildev\beacon\Plugin;
use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\errors\ImageTransformException;
use craft\helpers\UrlHelper;
use yii\base\Component;

/**
 * @phpstan-import-type MetaTag from \anvildev\beacon\models\SeoMeta
 */
class MetaResolverService extends Component
{
    /**
     * Per-request memoisation. GraphQL `entries { beacon { ... } }` resolves
     * one entry per row — without this cache, a 100-entry query produced
     * 100 redundant `resolveSocialImage` + hreflang chain executions.
     * Cleared between requests automatically (service is request-scoped).
     *
     * @var array<string, SeoMeta>
     */
    private array $resolveCache = [];

    /**
     * Resolves the final SEO meta for an entry by layering entry-level field values
     * over section and global defaults. Memoized per request so repeated calls in a
     * single element query are cheap.
     *
     * @param array<string,mixed> $entryFieldValue
     * @param array<string,mixed> $geoDefaults
     * @param list<string> $bundleSchemaTypes
     */
    public function resolve(
        array $entryFieldValue,
        string $entryTitle,
        string $siteName,
        array $geoDefaults,
        ?string $entryUrl = null,
        ?Entry $entry = null,
        array $bundleSchemaTypes = [],
    ): SeoMeta {
        $cacheKey = $this->buildResolveCacheKey($entry, $entryFieldValue, $entryUrl, $bundleSchemaTypes);
        if ($cacheKey !== null && isset($this->resolveCache[$cacheKey])) {
            return clone $this->resolveCache[$cacheKey];
        }

        $meta = new SeoMeta();
        $effectiveDefaults = $this->resolveEffectiveDefaults($geoDefaults, $entry);
        $meta->sourceMap = [
            'title' => (isset($effectiveDefaults['titleTemplate']) && $effectiveDefaults['titleTemplate'] !== ($geoDefaults['titleTemplate'] ?? null)) ? 'section' : 'global',
            'description' => (isset($effectiveDefaults['descriptionTemplate']) && $effectiveDefaults['descriptionTemplate'] !== ($geoDefaults['descriptionTemplate'] ?? null)) ? 'section' : 'global',
            'canonical' => 'auto',
            'robots' => 'entry',
        ];

        // Section templates + the tokens they can use. Heavier entry lookups
        // (author/parent) are only resolved when the template references them,
        // so the common case stays query-free on the render hot path.
        $titleTemplate = (string) ($effectiveDefaults['titleTemplate'] ?? '{title}');
        $descriptionTemplate = trim((string) ($effectiveDefaults['descriptionTemplate'] ?? ''));
        $tokens = $this->buildTemplateTokens($titleTemplate . ' ' . $descriptionTemplate, $entryTitle, $siteName, $entry);

        $explicitTitle = $entryFieldValue['title'] ?? null;
        if ($explicitTitle) {
            $meta->title = (string) $explicitTitle;
            $meta->sourceMap['title'] = 'entry';
        } else {
            $meta->title = strtr($titleTemplate, $tokens);
        }

        $explicitDescription = trim((string) ($entryFieldValue['description'] ?? ''));
        if ($explicitDescription !== '') {
            $meta->description = $explicitDescription;
            $meta->sourceMap['description'] = 'entry';
        } else {
            $meta->description = $descriptionTemplate !== '' ? strtr($descriptionTemplate, $tokens) : '';
        }

        $rawCanonical = $entryFieldValue['canonical'] ?? null;
        $meta->canonical = ($rawCanonical !== null && $rawCanonical !== '')
            ? $this->normalizePublicUrl((string) $rawCanonical)
            : null;
        if ($meta->canonical !== null) {
            $meta->sourceMap['canonical'] = 'entry';
        }

        $robotsFlags = is_array($entryFieldValue['robots'] ?? null) ? $entryFieldValue['robots'] : [];
        $meta->robots = RobotsDirectives::resolveActive($robotsFlags, $this->resolveRobotsEnabledMap());
        if ($this->shouldForceNoindexOnCurrentEnvironment() && !in_array('noindex', $meta->robots, true)) {
            $meta->robots[] = 'noindex';
            $meta->sourceMap['robots'] = 'runtime';
        }

        // AI-usage policy (entry → section → global). Its noai/noimageai tokens
        // ride on $meta->robots so the robots tag and X-Robots-Tag both carry
        // them; the policy itself drives TDMRep meta + the Content-Usage header.
        $meta->aiUsagePolicy = $this->resolveAiUsagePolicy($entryFieldValue, $entry, $geoDefaults);
        foreach (AiUsagePolicy::robotsTokens($meta->aiUsagePolicy) as $token) {
            if (!in_array($token, $meta->robots, true)) {
                $meta->robots[] = $token;
            }
        }

        $effectiveUrl = $meta->canonical ?? $this->normalizePublicUrl($entryUrl);
        $socialImage = $this->resolveSocialImage($entryFieldValue, $effectiveDefaults);

        $defaultOgType = $this->bundleSchemaIncludesArticle($bundleSchemaTypes) ? 'article' : 'website';
        $defaultTwitterCard = $socialImage !== null ? 'summary_large_image' : 'summary';

        $meta->articleTimes = $this->hydrateArticleTimes($entry);

        $meta->openGraph = [
            'title' => $meta->title,
            'description' => $meta->description !== '' ? $meta->description : null,
            'image' => $socialImage['url'] ?? null,
            'type' => $defaultOgType,
            'siteName' => $siteName,
            'url' => $effectiveUrl,
            'imageWidth' => !empty($socialImage['includeDimensions']) ? ($socialImage['width'] ?? null) : null,
            'imageHeight' => !empty($socialImage['includeDimensions']) ? ($socialImage['height'] ?? null) : null,
            'imageAlt' => $socialImage['alt'] ?? null,
            'locale' => $this->resolveOgLocale($entry),
        ];

        $meta->twitter = [
            'card' => $defaultTwitterCard,
            'title' => $meta->title,
            'description' => $meta->description !== '' ? $meta->description : null,
            'image' => $socialImage['url'] ?? null,
            'site' => $this->formatTwitterSiteMeta($effectiveDefaults['defaultTwitterSite'] ?? null),
            'creator' => $this->resolveTwitterCreator($entryFieldValue, $entry),
        ];

        $meta->openGraph = $this->mergeSocialOverrides($meta->openGraph, $entryFieldValue['openGraph'] ?? null);
        $meta->twitter = $this->mergeSocialOverrides($meta->twitter, $entryFieldValue['twitter'] ?? null);

        if (array_key_exists('site', $meta->twitter)) {
            $meta->twitter['site'] = $this->formatTwitterSiteMeta($meta->twitter['site']);
        }
        if (array_key_exists('creator', $meta->twitter)) {
            $meta->twitter['creator'] = $this->formatTwitterSiteMeta($meta->twitter['creator']);
        }

        $meta->openGraph['url'] = $this->normalizePublicUrl(isset($meta->openGraph['url']) ? (string) $meta->openGraph['url'] : null);
        if (!empty($meta->openGraph['image'])) {
            $meta->openGraph['image'] = $this->normalizePublicUrl((string) $meta->openGraph['image']);
        }
        if (!empty($meta->twitter['image'])) {
            $meta->twitter['image'] = $this->normalizePublicUrl((string) $meta->twitter['image']);
        }

        $meta->alternates = [];
        if ($entry instanceof Entry) {
            $hreflang = Plugin::$plugin->get('hreflang', false);
            if ($hreflang instanceof HreflangService) {
                $meta->alternates = $hreflang->resolveAlternates($entry);
            }
        }

        // Repeatable meta that can't live in the name-keyed tag map:
        // og:locale:alternate (one per other propagated locale) + article:author.
        $meta->extraMetaTags = array_merge(
            $this->buildLocaleAlternateTags($meta),
            ($meta->openGraph['type'] ?? '') === 'article'
                ? $this->buildArticleAuthorTags($entryFieldValue, $entry)
                : [],
        );

        if ($cacheKey !== null) {
            $this->resolveCache[$cacheKey] = clone $meta;
        }
        return $meta;
    }

    /**
     * Returns null for calls without a concrete entry (homepage / `craft.beacon.set()`
     * override paths) to avoid poisoning the cache for subsequent resolves.
     *
     * @param array<string,mixed> $entryFieldValue
     * @param list<string> $bundleSchemaTypes
     */
    private function buildResolveCacheKey(
        ?Entry $entry,
        array $entryFieldValue,
        ?string $entryUrl,
        array $bundleSchemaTypes,
    ): ?string {
        if ($entry === null || $entry->id === null) {
            return null;
        }
        $updated = $entry->dateUpdated?->format('U') ?? '0';
        $payload = $updated
            . '|' . ($entryUrl ?? '')
            . '|' . hash('sha256', serialize($entryFieldValue))
            . '|' . implode(',', $bundleSchemaTypes);
        return $entry->id . ':' . $entry->siteId . ':' . hash('sha256', $payload);
    }

    /**
     * Entry-derived tokens ({section}, {type}, {author}, {parent}) are resolved
     * only when referenced in `$combined` so unused tokens incur no lookup.
     *
     * @return array<string, string>
     */
    private function buildTemplateTokens(string $combined, string $entryTitle, string $siteName, ?Entry $entry): array
    {
        $tokens = [
            '{title}' => $entryTitle,
            '{siteName}' => $siteName,
        ];
        if ($entry === null) {
            return $tokens;
        }
        if (str_contains($combined, '{section}')) {
            $tokens['{section}'] = $entry->getSection()?->name ?? '';
        }
        if (str_contains($combined, '{type}')) {
            $tokens['{type}'] = $entry->getType()->name;
        }
        if (str_contains($combined, '{author}')) {
            $tokens['{author}'] = $entry->getAuthor()?->name ?? '';
        }
        if (str_contains($combined, '{parent}')) {
            $parent = $entry->getParent();
            $tokens['{parent}'] = $parent instanceof Entry ? (string) $parent->title : '';
        }
        return $tokens;
    }

    /**
     * @param array<string,mixed> $globalDefaults
     * @return array<string,mixed>
     */
    private function resolveEffectiveDefaults(array $globalDefaults, ?Entry $entry): array
    {
        if (!$entry instanceof Entry) {
            return $globalDefaults;
        }

        $sectionHandle = $entry->getSection()?->handle ?? null;
        if (!is_string($sectionHandle) || $sectionHandle === '') {
            return $globalDefaults;
        }

        $sectionDefaults = $globalDefaults['sectionSeoDefaults'] ?? null;
        if (!is_array($sectionDefaults)) {
            return $globalDefaults;
        }

        $row = $sectionDefaults[$sectionHandle] ?? null;
        if (!is_array($row)) {
            return $globalDefaults;
        }

        $merged = $globalDefaults;
        foreach (['titleTemplate', 'descriptionTemplate'] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * @param array<string,mixed>|null $overrides
     * @param array<string,mixed> $defaults
     * @return array<string,mixed>
     */
    private function mergeSocialOverrides(array $defaults, ?array $overrides): array
    {
        if ($overrides === null) {
            return $defaults;
        }

        foreach ($overrides as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, $defaults) || $value === '') {
                continue;
            }
            $defaults[$key] = $value;
        }

        return $defaults;
    }

    /**
     * @param array<string,mixed> $entryFieldValue
     * @param array<string,mixed> $geoDefaults
     * @return array{url: string, alt: ?string, width: ?int, height: ?int, includeDimensions: bool}|null
     */
    private function resolveSocialImage(array $entryFieldValue, array $geoDefaults): ?array
    {
        $direct = $entryFieldValue['ogImage'] ?? ($entryFieldValue['openGraph']['image'] ?? null);
        if (is_string($direct) && $direct !== '') {
            return ['url' => $direct, 'alt' => null, 'width' => null, 'height' => null, 'includeDimensions' => false];
        }

        $id = $entryFieldValue['ogImageId'] ?? null;
        if (!is_numeric($id) || (int) $id <= 0) {
            $fallbackId = $geoDefaults['defaultSocialImageId'] ?? null;
            if (!is_numeric($fallbackId) || (int) $fallbackId <= 0) {
                return null;
            }
            $id = $fallbackId;
        }

        $asset = Assets::findById((int) $id);
        if ($asset === null) {
            return null;
        }

        $rawTransform = strtolower(trim((string) ($geoDefaults['socialImageTransform'] ?? 'beaconSocial')));
        // `none` / `original` / `full` all mean "serve the asset URL untransformed".
        $useNamedTransform = !in_array($rawTransform, ['', 'none', 'original', 'full'], true);
        $transformHandle = $useNamedTransform
            ? trim((string) ($geoDefaults['socialImageTransform'] ?? 'beaconSocial'))
            : null;

        $resolved = self::resolveAssetUrlWithTransform($asset, $transformHandle);
        $url = $resolved['url'];
        if (!is_string($url) || $url === '') {
            return null;
        }

        $width = $asset->getWidth();
        $height = $asset->getHeight();
        $includeDimensions = !$resolved['transformResolved'] && is_int($width) && is_int($height);

        return [
            'url' => $url,
            'width' => is_int($width) ? $width : null,
            'height' => is_int($height) ? $height : null,
            'alt' => $asset->title ?: null,
            'includeDimensions' => $includeDimensions,
        ];
    }

    /**
     * @param list<string> $types
     */
    private function bundleSchemaIncludesArticle(array $types): bool
    {
        foreach ($types as $type) {
            if (is_string($type) && strcasecmp(trim($type), 'Article') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * twitter:site expects an @handle for profiles.
     *
     * @see https://developer.x.com/en/docs/twitter-for-websites/cards/overview/markup
     */
    private function formatTwitterSiteMeta(string|int|float|bool|null $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return str_starts_with($value, '@') ? $value : '@' . ltrim($value, '@');
    }

    /**
     * og:locale for the entry's site (or the current site when resolving without
     * an entry). Craft stores BCP-47 (`en-US`); Open Graph wants `en_US`.
     */
    private function resolveOgLocale(?Entry $entry): ?string
    {
        if (!class_exists(\Craft::class) || Craft::$app === null) {
            return null;
        }
        $sites = Craft::$app->getSites();
        $site = $entry instanceof Entry ? $sites->getSiteById((int) $entry->siteId) : $sites->getCurrentSite();
        $language = trim((string) ($site?->language ?? ''));
        return $language !== '' ? $this->toOgLocale($language) : null;
    }

    private function toOgLocale(string $language): string
    {
        return str_replace('-', '_', $language);
    }

    /**
     * One `og:locale:alternate` per *other* propagated locale. Reuses the
     * already-resolved hreflang alternates (zero extra queries), so this emits
     * only when hreflang is enabled on a multi-site install — the same
     * multi-locale signal. x-default and the current locale are excluded.
     *
     * @return list<MetaTag>
     */
    private function buildLocaleAlternateTags(SeoMeta $meta): array
    {
        $current = (string) ($meta->openGraph['locale'] ?? '');
        $seen = [];
        $rows = [];
        foreach ($meta->alternates as $alternate) {
            $lang = trim((string) $alternate['hreflang']);
            if ($lang === '' || $lang === 'x-default') {
                continue;
            }
            $locale = $this->toOgLocale($lang);
            if ($locale === $current || isset($seen[$locale])) {
                continue;
            }
            $seen[$locale] = true;
            $rows[] = ['attr' => 'property', 'name' => 'og:locale:alternate', 'content' => $locale];
        }
        return $rows;
    }

    /**
     * `article:author` per attached author — the public profile URL when author
     * pages are enabled and the author resolves a URL, otherwise the author name.
     *
     * @param array<string,mixed> $entryFieldValue
     * @return list<MetaTag>
     */
    private function buildArticleAuthorTags(array $entryFieldValue, ?Entry $entry): array
    {
        $rows = [];
        foreach ($this->loadAuthors($entryFieldValue, $entry) as $author) {
            $url = $author->getUriFormat() !== null ? ($author->getUrl() ?? '') : '';
            $content = $url !== '' ? $url : trim((string) $author->title);
            if ($content !== '') {
                $rows[] = ['attr' => 'property', 'name' => 'article:author', 'content' => $content];
            }
        }
        return $rows;
    }

    /**
     * twitter:creator from the primary (first) author's X/Twitter `sameAs` URL,
     * as an @handle. Omitted when there is no author or no X profile — never
     * falls back to twitter:site.
     *
     * @param array<string,mixed> $entryFieldValue
     */
    private function resolveTwitterCreator(array $entryFieldValue, ?Entry $entry): ?string
    {
        $authors = $this->loadAuthors($entryFieldValue, $entry);
        $primary = $authors[0] ?? null;
        if (!$primary instanceof AuthorElement || !is_array($primary->sameAs)) {
            return null;
        }
        foreach ($primary->sameAs as $url) {
            $url = trim((string) $url);
            if (preg_match('#^https?://(?:www\.)?(?:x|twitter)\.com/@?([A-Za-z0-9_]{1,15})#i', $url, $m) === 1) {
                return '@' . $m[1];
            }
        }
        return null;
    }

    /**
     * Load the entry's attached Beacon authors in posted order, scoped to the
     * entry's site. Returns [] when there are no author ids or Craft is absent.
     *
     * @param array<string,mixed> $entryFieldValue
     * @return list<AuthorElement>
     */
    private function loadAuthors(array $entryFieldValue, ?Entry $entry): array
    {
        if (!class_exists(\Craft::class) || Craft::$app === null) {
            return [];
        }
        $ids = Ids::positiveInts((array) ($entryFieldValue['authorIds'] ?? []));
        if ($ids === []) {
            return [];
        }
        /** @var list<AuthorElement> $authors */
        $authors = AuthorElement::find()
            ->id($ids)
            ->siteId($entry instanceof Entry ? $entry->siteId : '*')
            ->fixedOrder(true)
            ->all();
        return $authors;
    }

    /**
     * @return array<string, string>|null
     */
    private function hydrateArticleTimes(?Entry $entry): ?array
    {
        if ($entry === null) {
            return null;
        }

        $times = array_filter([
            'publishedTime' => $entry->postDate?->format(DATE_ATOM),
            'modifiedTime' => $entry->dateUpdated?->format(DATE_ATOM),
        ]);

        return $times !== [] ? $times : null;
    }

    private function normalizePublicUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('#^([a-z][a-z0-9+\-.]*:)#i', $trimmed)) {
            return $trimmed;
        }

        if (Craft::$app === null || !Craft::$app->getIsInstalled()) {
            return $trimmed;
        }

        return UrlHelper::siteUrl($trimmed);
    }

    /**
     * Effective AI-usage policy with entry beating section beating the global
     * default. Pure (no Plugin lookup) so it works in unit tests; reads the
     * per-section value straight from `sectionSeoDefaults[<handle>][aiUsage]`.
     *
     * @param array<string,mixed> $entryFieldValue
     * @param array<string,mixed> $geoDefaults
     */
    private function resolveAiUsagePolicy(array $entryFieldValue, ?Entry $entry, array $geoDefaults): string
    {
        $entryPolicy = AiUsagePolicy::normalizeOrInherit(
            is_string($entryFieldValue['aiUsage'] ?? null) ? $entryFieldValue['aiUsage'] : null,
        );

        $sectionPolicy = null;
        $handle = $entry instanceof Entry ? ($entry->getSection()?->handle ?? null) : null;
        if (is_string($handle) && $handle !== '') {
            $sectionDefaults = $geoDefaults['sectionSeoDefaults'] ?? null;
            $row = is_array($sectionDefaults) ? ($sectionDefaults[$handle] ?? null) : null;
            if (is_array($row)) {
                $sectionPolicy = AiUsagePolicy::normalizeOrInherit(
                    is_string($row['aiUsage'] ?? null) ? $row['aiUsage'] : null,
                );
            }
        }

        $global = AiUsagePolicy::normalize(
            is_string($geoDefaults['aiUsagePolicy'] ?? null) ? $geoDefaults['aiUsagePolicy'] : null,
        );

        return $entryPolicy ?? $sectionPolicy ?? $global;
    }

    /**
     * The unit tests run with no Plugin instance, so when settings aren't
     * available we treat all directives as enabled — that preserves the
     * pre-existing "every flag in the field value gets emitted" contract.
     *
     * @return array<string,bool>
     */
    private function resolveRobotsEnabledMap(): array
    {
        if (!class_exists(\Craft::class) || Craft::$app === null || Plugin::$plugin === null) {
            return array_fill_keys(RobotsDirectives::keys(), true);
        }
        return RobotsDirectives::resolveEnabledMap(
            Plugin::$plugin->settings->get()->robotsDirectivesEnabled,
        );
    }

    /**
     * Noindex is added automatically whenever Craft's resolved environment
     * is not production. To publish indexable pages, set
     * `CRAFT_ENVIRONMENT=production`.
     */
    private function shouldForceNoindexOnCurrentEnvironment(): bool
    {
        if (!class_exists(\Craft::class) || Craft::$app === null || Plugin::$plugin === null) {
            return false;
        }
        return EnvironmentMapper::resolveActive() !== Environment::Production;
    }

    /**
     * Unknown transform handles fall back to the native URL with a warning,
     * so a typo in `socialImageTransform` doesn't break meta-tag rendering.
     *
     * @return array{url:?string, transformResolved:bool}
     */
    public static function resolveAssetUrlWithTransform(Asset $asset, ?string $transformHandle): array
    {
        if ($transformHandle === null || $transformHandle === '') {
            return ['url' => $asset->getUrl(), 'transformResolved' => false];
        }

        if (Craft::$app !== null && Craft::$app->getIsInstalled()) {
            $registered = Craft::$app->getImageTransforms()->getTransformByHandle($transformHandle);
            if ($registered === null) {
                Craft::warning(
                    "Beacon: socialImageTransform '{$transformHandle}' is not registered with Craft; "
                    . 'falling back to the native asset URL.',
                    'beacon',
                );
                return ['url' => $asset->getUrl(), 'transformResolved' => false];
            }
        }

        try {
            return ['url' => $asset->getUrl($transformHandle), 'transformResolved' => true];
        } catch (ImageTransformException $e) { // @phpstan-ignore catch.neverThrown
            Craft::warning(
                "Beacon: socialImageTransform '{$transformHandle}' failed to resolve; "
                . 'falling back to the native asset URL. ' . $e->getMessage(),
                'beacon',
            );
            return ['url' => $asset->getUrl(), 'transformResolved' => false];
        }
    }
}
