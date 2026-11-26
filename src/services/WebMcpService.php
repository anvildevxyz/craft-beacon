<?php

namespace anvildev\beacon\services;

use anvildev\beacon\helpers\Json;
use anvildev\beacon\helpers\SeoFieldReader;
use anvildev\beacon\integrations\CommerceIntegration;
use anvildev\beacon\Plugin;
use Craft;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\models\Site;
use yii\base\Component;

/**
 * Builds the browser-side AI-agent tools script (`/webmcp.js`) and backs its
 * read-only data tools (search, page lookup, section listing) with Beacon's
 * existing content APIs.
 *
 * This is a public, unauthenticated, read-only surface for an AI agent
 * running inside a *visitor's* browser — distinct from the authenticated,
 * server-side MCP tools served at `/beacon/mcp` (see {@see McpService}),
 * which target coding/editorial agents.
 *
 * @author anvildev
 */
class WebMcpService extends Component
{
    /**
     * The opt-in script served at `/webmcp.js` for a site. Declares three
     * read-only tools backed by server endpoints (`searchContent`,
     * `getPage`, `listSections`) plus a purely client-side `navigateTo`.
     */
    public function scriptBody(Site $site): string
    {
        return $this->buildScript(
            UrlHelper::siteUrl('beacon/webmcp/search', null, null, $site->id),
            UrlHelper::siteUrl('beacon/webmcp/page', null, null, $site->id),
            UrlHelper::siteUrl('beacon/webmcp/sections', null, null, $site->id),
        );
    }

    /**
     * Searches live, indexable entries on the site and returns the top
     * matches as `{title, url, snippet}` rows.
     *
     * @return list<array{title:string, url:string, snippet:?string}>
     */
    public function search(Site $site, string $query, int $limit = 10): array
    {
        $trimmed = trim($query);
        if ($trimmed === '' || $limit < 1) {
            return [];
        }

        $hits = [];
        $entries = Entry::find()
            ->siteId($site->id)
            ->status(Entry::STATUS_LIVE)
            ->search($trimmed)
            ->limit($limit * 3);

        foreach ($entries->each(50) as $entry) {
            assert($entry instanceof Entry);
            $url = SeoFieldReader::indexableUrl($entry);
            if ($url === null) {
                continue;
            }
            $hits[] = $this->buildSearchHit((string) $entry->title, $url, SeoFieldReader::readDescriptionFor($entry));
            if (count($hits) >= $limit) {
                break;
            }
        }

        return $hits;
    }

    /**
     * Resolves a page URL to its live entry (or Commerce product) and
     * returns its structured content, reusing the same GEO Markdown body
     * served at the entry's `.md` export. Returns null when the URL doesn't
     * resolve, or the element is noindex, or it has no Markdown export.
     *
     * @return ?array{title:string, url:string, description:?string, markdown:string}
     */
    public function page(Site $site, string $url): ?array
    {
        $uri = $this->uriFromUrl($url, $site);
        $element = CommerceIntegration::findLiveMarkdownElement('uri', $uri, $site->id);
        if ($element === null) {
            return null;
        }

        $resolvedUrl = SeoFieldReader::indexableUrl($element);
        if ($resolvedUrl === null) {
            return null;
        }

        $markdown = Plugin::$plugin->geoMarkdownExport->resolveMarkdown($element);
        if ($markdown === null) {
            return null;
        }

        return $this->buildPageResult(
            SeoFieldReader::headlineFor($element),
            $resolvedUrl,
            SeoFieldReader::readDescriptionFor($element),
            $markdown,
        );
    }

    /**
     * Lists the sections included in the site's sitemap, as `{handle, name}`
     * rows — the same public-section scope the sitemap and llms.txt use.
     *
     * @return list<array{handle:string, name:string}>
     */
    public function sections(Site $site): array
    {
        $settings = Plugin::$plugin->siteSettings->getSitemap($site->id);
        $rows = [];
        foreach ($settings->includedSectionHandles() as $handle) {
            // Skip Commerce/integration placeholder handles (e.g. __products__) —
            // they have no matching craft\models\Section to read a name from.
            if (CommerceIntegration::isPlaceholderHandle($handle)) {
                continue;
            }
            $section = Craft::$app->getEntries()->getSectionByHandle($handle);
            if ($section === null) {
                continue;
            }
            $rows[] = ['handle' => $handle, 'name' => (string) $section->name];
        }
        return $rows;
    }

    /**
     * Converts an absolute URL (or a bare path) to the site-relative URI
     * Craft's element queries expect, stripping the site's base path when
     * present. Falls back to Craft's `__home__` convention for an empty path.
     */
    private function uriFromUrl(string $url, Site $site): string
    {
        // parse_url() returns false (not null) for a malformed URL, which `??`
        // does not catch — an explicit is_string() check is required so a
        // bad input resolves to the empty/home path rather than to the raw,
        // unparsed URL string being used as the path.
        $rawPath = parse_url($url, PHP_URL_PATH);
        $path = trim(is_string($rawPath) ? $rawPath : '', '/');
        $rawBase = parse_url((string) ($site->getBaseUrl() ?? ''), PHP_URL_PATH);
        $base = trim(is_string($rawBase) ? $rawBase : '', '/');

        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = trim(substr($path, strlen($base)), '/');
        }

        return $path === '' ? '__home__' : $path;
    }

    /**
     * @return array{title:string, url:string, snippet:?string}
     */
    private function buildSearchHit(string $title, string $url, ?string $description): array
    {
        return ['title' => $title, 'url' => $url, 'snippet' => $description];
    }

    /**
     * @return array{title:string, url:string, description:?string, markdown:string}
     */
    private function buildPageResult(string $title, string $url, ?string $description, string $markdown): array
    {
        return ['title' => $title, 'url' => $url, 'description' => $description, 'markdown' => $markdown];
    }

    /**
     * Pure JS templating for the served script, given its three absolute
     * tool-endpoint URLs. `navigateTo` is implemented purely client-side
     * (same-origin check + `window.location.assign`) — no server round trip.
     */
    private function buildScript(string $searchUrl, string $pageUrl, string $sectionsUrl): string
    {
        $endpoints = Json::encode([
            'search' => $searchUrl,
            'page' => $pageUrl,
            'sections' => $sectionsUrl,
        ], JSON_UNESCAPED_SLASHES);

        return <<<JS
        /* Beacon WebMCP — read-only AI-agent tools for this page. See the Beacon docs. */
        (function () {
          var endpoints = {$endpoints};

          function getJson(url) {
            return fetch(url, { headers: { Accept: 'application/json' } }).then(function (res) {
              if (!res.ok) { throw new Error('Beacon WebMCP request failed: ' + res.status); }
              return res.json();
            });
          }

          window.beaconWebMcp = {
            searchContent: function (query, limit) {
              if (typeof query !== 'string' || query === '') {
                return Promise.reject(new Error('Beacon WebMCP: searchContent(query) requires a non-empty query string.'));
              }
              var params = new URLSearchParams({ q: query, limit: String(typeof limit === 'number' ? limit : 10) });
              return getJson(endpoints.search + '?' + params.toString());
            },
            getPage: function (url) {
              if (typeof url !== 'string' || url === '') {
                return Promise.reject(new Error('Beacon WebMCP: getPage(url) requires a non-empty URL string.'));
              }
              var params = new URLSearchParams({ url: url });
              return getJson(endpoints.page + '?' + params.toString());
            },
            listSections: function () {
              return getJson(endpoints.sections);
            },
            navigateTo: function (url) {
              var target = new URL(url, window.location.href);
              if (target.origin !== window.location.origin) {
                return Promise.reject(new Error('Beacon WebMCP: cross-origin navigation is not allowed.'));
              }
              window.location.assign(target.href);
              return Promise.resolve({ navigated: true, url: target.href });
            },
          };
        })();
        JS;
    }
}
