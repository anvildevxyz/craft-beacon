<?php

namespace anvildev\beacon\services\markdown;

use Craft;
use craft\helpers\StringHelper;
use DOMDocument;
use DOMNode;
use DOMXPath;
use League\HTMLToMarkdown\HtmlConverter;
use Throwable;

/**
 * Shared HTML→clean-content helper used by both the GEO Markdown export
 * pipeline ({@see \anvildev\beacon\services\GeoMarkdownExportService}) and
 * the GEO score content walker
 * ({@see \anvildev\beacon\services\scoring\ContentWalker}).
 *
 * Owns three independent transforms:
 *
 *   1. {@see self::extractMarkedContent()} — honours the `{% beaconmd %}` /
 *      `{% beaconmdignore %}` Twig markers as emitted by
 *      {@see \anvildev\beacon\twig\nodes\BeaconMdNode} /
 *      {@see \anvildev\beacon\twig\nodes\BeaconMdIgnoreNode}.
 *   2. {@see self::stripElementsWithClasses()} — DOM-level removal of
 *      operator-configured chrome classes (nav, footer, asides).
 *   3. {@see self::htmlToMarkdown()} — wraps `league/html-to-markdown`
 *      with Beacon's standard converter config.
 *
 * Constants for the keep/drop markers live here as the canonical home;
 * Twig nodes ({@see \anvildev\beacon\twig\nodes\BeaconMdNode},
 * {@see \anvildev\beacon\twig\nodes\BeaconMdIgnoreNode}) reference them
 * directly.
 */
final class HtmlChromeStripper
{
    public const MARKER_KEEP_START = '<!--beacon:md-keep-start-->';
    public const MARKER_KEEP_END = '<!--beacon:md-keep-end-->';
    public const MARKER_DROP_START = '<!--beacon:md-drop-start-->';
    public const MARKER_DROP_END = '<!--beacon:md-drop-end-->';

    /**
     * Strip YII view-state placeholders left by `craft\web\View::renderTemplate()`.
     * These never carry useful body content; they leak from layout templates.
     */
    public function stripYiiBlockMarkers(string $html): string
    {
        return preg_replace('/<!\[CDATA\[YII-BLOCK-[A-Z-]+\]\]>/', '', $html) ?? $html;
    }

    /**
     * Removes content between `MARKER_DROP_*` and — when any `MARKER_KEEP_*`
     * is present — limits output to content between keep markers.
     */
    public function extractMarkedContent(string $html): string
    {
        $html = preg_replace(
            '/' . preg_quote(self::MARKER_DROP_START, '/') . '.*?' . preg_quote(self::MARKER_DROP_END, '/') . '/s',
            '',
            $html,
        ) ?? $html;

        if (!str_contains($html, self::MARKER_KEEP_START)) {
            return $html;
        }

        // Capture text inside KEEP markers; `|$` lets a final unterminated
        // region match through end-of-string.
        $start = preg_quote(self::MARKER_KEEP_START, '/');
        $end = preg_quote(self::MARKER_KEEP_END, '/');
        preg_match_all('/' . $start . '(.*?)(?:' . $end . '|$)/s', $html, $matches);
        return implode("\n\n", $matches[1]);
    }

    /**
     * @param list<string> $classes
     */
    public function stripElementsWithClasses(string $html, array $classes): string
    {
        $classes = array_values(array_filter(array_map('trim', $classes), static fn(string $c): bool => $c !== ''));
        if ($classes === []) {
            return $html;
        }

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="__beacon_root__">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return $html;
        }

        $xpath = new DOMXPath($doc);
        foreach ($classes as $class) {
            $expr = sprintf(
                "//*[contains(concat(' ', normalize-space(@class), ' '), ' %s ')]",
                str_replace("'", "&apos;", $class),
            );
            $nodes = $xpath->query($expr);
            if ($nodes === false) {
                continue;
            }
            $toRemove = [];
            foreach ($nodes as $node) {
                if ($node instanceof DOMNode) {
                    $toRemove[] = $node;
                }
            }
            foreach ($toRemove as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $root = $doc->getElementById('__beacon_root__');
        if (!$root instanceof DOMNode) {
            return $html;
        }
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    public function htmlToMarkdown(string $html): string
    {
        $converter = new HtmlConverter([
            'strip_tags' => true,
            'remove_nodes' => 'script style noscript iframe form svg head header nav footer aside',
            'hard_break' => true,
            'header_style' => 'atx',
            'use_autolinks' => false,
        ]);

        try {
            $md = $converter->convert($html);
        } catch (Throwable $e) {
            Craft::warning('Beacon HTML→MD conversion failed: ' . $e->getMessage(), 'beacon');
            return $this->flattenHtml($html);
        }

        $md = preg_replace("/\n{3,}/", "\n\n", $md) ?? $md;
        return trim($md);
    }

    public function flattenHtml(string $html): string
    {
        // Charset normally comes from the running Craft app; fall back to
        // UTF-8 when running outside a Craft bootstrap (unit tests, CLI tools).
        // PHPStan thinks Craft::$app is always set because the static type
        // is non-nullable, but in test/CLI contexts it can genuinely be null.
        /** @phpstan-ignore-next-line isset.staticProperty */
        $charset = isset(Craft::$app) ? (string) Craft::$app->charset : 'UTF-8';
        $text = strip_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, $charset));

        return trim(StringHelper::collapseWhitespace($text));
    }
}
