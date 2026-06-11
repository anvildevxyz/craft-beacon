<?php

namespace anvildev\beacon\services\scoring;

use anvildev\beacon\helpers\ElementHtmlRenderer;
use anvildev\beacon\Plugin;
use anvildev\beacon\services\markdown\HtmlChromeStripper;
use craft\base\ElementInterface;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Shared content analyser for structural GEO score pillars (claim-based
 * headings, chunkability, fact density, outbound citations). Reads the
 * entry's content in one of two modes and emits a flat list of
 * {@see ContentNode} objects in document order.
 *
 * Two modes, controlled by `Settings::$geoScoreContentRenderMode`:
 *
 *   - `bodyField`  — pulls the value of `Settings::$geoMarkdownBodyFieldHandle`
 *                    (default `body`) and walks the field's HTML. Cheap and
 *                    deterministic; misses content composed across Matrix
 *                    blocks, Neo blocks, or rendered Twig islands.
 *   - `fullRender` — invokes the entry's site Twig template, strips chrome
 *                    via {@see HtmlChromeStripper}, then walks the result.
 *                    Catches Vue / Sprig / Datastar composition AI crawlers
 *                    can't execute. Slower (one render per score compute),
 *                    but the score itself runs async via
 *                    {@see \anvildev\beacon\jobs\RecomputeGeoScoreJob}, so
 *                    editors never block on it.
 *
 * The walker does not cache. Caching is a {@see \anvildev\beacon\services\GeoScoreService}
 * concern — the service walks once per `compute()` call and feeds the same
 * AST to every structural pillar through {@see PillarContext}.
 */
final class ContentWalker
{
    /**
     * Body-field handles probed in order when no value is found at
     * `Settings::$geoMarkdownBodyFieldHandle`. Mirrors the same fallback
     * list used by {@see \anvildev\beacon\services\GeoMarkdownExportService}.
     */
    private const FALLBACK_HANDLES = ['body', 'content', 'articleBody', 'description'];

    public function __construct(
        private readonly HtmlChromeStripper $stripper = new HtmlChromeStripper(),
    ) {
    }

    /**
     * @return list<ContentNode>
     */
    public function walk(ElementInterface $element, int $siteId): array
    {
        $settings = Plugin::$plugin->settings->get();
        $mode = $settings->effectiveGeoScoreRenderMode();

        $html = $mode === 'fullRender'
            ? $this->fullRenderHtml($element, $settings->geoMarkdownExcludedClasses)
            : $this->bodyFieldHtml($element, $settings->geoMarkdownBodyFieldHandle);

        // Mirror GeoMarkdownExportService: fall back to body field when template render fails.
        if (($html === null || trim($html) === '') && $mode === 'fullRender') {
            $html = $this->bodyFieldHtml($element, $settings->geoMarkdownBodyFieldHandle);
        }

        if ($html === null || trim($html) === '') {
            return [];
        }

        return $this->parseHtml($html, $this->elementHost($element));
    }

    /**
     * Parse raw HTML into a normalised AST. `$selfHost` is used to flag
     * outbound links (`isInternal = false`) for citation scoring pillars.
     * Exposed so tests can invoke the pure parser without a Craft bootstrap.
     *
     * @return list<ContentNode>
     */
    public function parseHtml(string $html, string $selfHost = ''): array
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="__beacon_walker_root__">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return [];
        }

        $root = $doc->getElementById('__beacon_walker_root__');
        if (!$root instanceof DOMElement) {
            return [];
        }

        $nodes = [];
        $this->collect($root, $nodes, $selfHost);
        return $nodes;
    }

    /**
     * @param list<string> $excludedClasses
     */
    private function fullRenderHtml(ElementInterface $element, array $excludedClasses): ?string
    {
        $html = ElementHtmlRenderer::render($element);
        if ($html === null) {
            return null;
        }
        $html = $this->stripper->stripYiiBlockMarkers($html);
        $html = $this->stripper->extractMarkedContent($html);
        if ($excludedClasses !== []) {
            $html = $this->stripper->stripElementsWithClasses($html, $excludedClasses);
        }
        return $html;
    }

    private function bodyFieldHtml(ElementInterface $element, string $primaryHandle): ?string
    {
        $primary = trim($primaryHandle) !== '' ? trim($primaryHandle) : 'body';
        $layout = $element->getFieldLayout();
        if ($layout === null) {
            return null;
        }

        $candidates = array_values(array_unique(array_filter(array_merge([$primary], self::FALLBACK_HANDLES))));
        foreach ($candidates as $handle) {
            if ($layout->getFieldByHandle($handle) === null) {
                continue;
            }
            $value = $element->getFieldValue($handle);
            if ($value === null || $value === '') {
                continue;
            }
            if ($value instanceof \Twig\Markup) {
                return (string) $value;
            }
            if (is_string($value)) {
                return $value;
            }
        }
        return null;
    }

    /**
     * @param list<ContentNode> $nodes
     */
    private function collect(DOMNode $parent, array &$nodes, string $selfHost): void
    {
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            // Heading: h1–h6. The first char 'h' + single digit is faster than
            // six equality checks — confirmed equivalent since nodeName is always
            // lower-ASCII after strtolower().
            if ($tag[0] === 'h' && isset($tag[1]) && $tag[1] >= '1' && $tag[1] <= '6' && strlen($tag) === 2) {
                $text = $this->cleanText($child->textContent);
                $nodes[] = new ContentNode(
                    type: ContentNode::TYPE_HEADING,
                    level: (int) $tag[1],
                    text: $text,
                    wordCount: ContentNode::countWords($text),
                );
                $this->extractLinks($child, $nodes, $selfHost);
                continue;
            }

            if ($tag === 'p') {
                $text = $this->cleanText($child->textContent);
                if ($text !== '') {
                    $nodes[] = new ContentNode(
                        type: ContentNode::TYPE_PARAGRAPH,
                        text: $text,
                        wordCount: ContentNode::countWords($text),
                    );
                }
                $this->extractLinks($child, $nodes, $selfHost);
                continue;
            }

            if ($tag === 'ul' || $tag === 'ol') {
                $items = [];
                foreach ($child->getElementsByTagName('li') as $li) {
                    $itemText = $this->cleanText($li->textContent);
                    if ($itemText !== '') {
                        $items[] = $itemText;
                    }
                }
                if ($items !== []) {
                    $concatenated = implode(' ', $items);
                    $nodes[] = new ContentNode(
                        type: ContentNode::TYPE_LIST,
                        text: $concatenated,
                        wordCount: ContentNode::countWords($concatenated),
                        items: $items,
                    );
                }
                $this->extractLinks($child, $nodes, $selfHost);
                continue;
            }

            if ($tag === 'table') {
                $text = $this->cleanText($child->textContent);
                if ($text !== '') {
                    $nodes[] = new ContentNode(
                        type: ContentNode::TYPE_TABLE,
                        text: $text,
                        wordCount: ContentNode::countWords($text),
                    );
                }
                $this->extractLinks($child, $nodes, $selfHost);
                continue;
            }

            if ($tag === 'pre' || $tag === 'code') {
                $text = $child->textContent;
                if (trim($text) !== '') {
                    $nodes[] = new ContentNode(
                        type: ContentNode::TYPE_CODE,
                        text: $text,
                        wordCount: ContentNode::countWords($text),
                    );
                }
                continue;
            }

            // Recurse into divs / article / section / main and other wrappers.
            $this->collect($child, $nodes, $selfHost);
        }
    }

    /**
     * @param list<ContentNode> $nodes
     */
    private function extractLinks(DOMElement $scope, array &$nodes, string $selfHost): void
    {
        if (!$scope->hasChildNodes()) {
            return;
        }
        foreach ($scope->getElementsByTagName('a') as $anchor) {
            $href = (string) $anchor->getAttribute('href');
            if ($href === '' || str_starts_with($href, '#')) {
                continue;
            }
            $text = $this->cleanText($anchor->textContent);
            $nodes[] = new ContentNode(
                type: ContentNode::TYPE_LINK,
                text: $text,
                wordCount: ContentNode::countWords($text),
                href: $href,
                isInternal: $this->isInternalHref($href, $selfHost),
            );
        }
    }

    private function cleanText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function isInternalHref(string $href, string $selfHost): bool
    {
        if ($href === '' || str_starts_with($href, '#')) {
            return true;
        }
        if (str_starts_with($href, '/') && !str_starts_with($href, '//')) {
            return true;
        }
        $host = parse_url($href, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            // mailto:, tel:, relative without leading slash — treat as internal.
            return true;
        }
        if ($selfHost === '') {
            return false;
        }
        return strtolower($host) === strtolower($selfHost);
    }

    private function elementHost(ElementInterface $element): string
    {
        $url = $element->getUrl();
        if (is_string($url) && $url !== '') {
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host)) {
                return $host;
            }
        }
        try {
            $baseUrl = $element->getSite()->getBaseUrl();
        } catch (\yii\base\InvalidConfigException) {
            $baseUrl = null;
        }
        if (is_string($baseUrl)) {
            $host = parse_url($baseUrl, PHP_URL_HOST);
            if (is_string($host)) {
                return $host;
            }
        }
        return '';
    }
}
