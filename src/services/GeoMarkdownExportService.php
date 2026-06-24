<?php

namespace anvildev\beacon\services;

use anvildev\beacon\helpers\ElementHtmlRenderer;
use anvildev\beacon\helpers\GeoMarkdownFrontMatter;
use anvildev\beacon\helpers\SeoFieldReader;
use anvildev\beacon\models\AiMarkdownOverride;
use anvildev\beacon\Plugin;
use anvildev\beacon\services\markdown\HtmlChromeStripper;
use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\models\Section;
use yii\base\Component;

class GeoMarkdownExportService extends Component
{
    private ?HtmlChromeStripper $chromeStripper = null;

    private function chromeStripper(): HtmlChromeStripper
    {
        return $this->chromeStripper ??= new HtmlChromeStripper();
    }

    /**
     * Build exportable Markdown for a live public element, or null when disallowed.
     * Returning null is the load-bearing signal: callers (GeoExportController,
     * GeoMarkdownNegotiator) translate it into a 404 — Markdown is a representation
     * choice, not an access boundary, so 403 would leak entry existence.
     */
    public function exportElement(ElementInterface $element): ?string
    {
        $settings = Plugin::$plugin->settings->get();
        if (!$settings->geoMarkdownEnabled) {
            $this->logExportDebug('GEO export skipped: geoMarkdownEnabled=false');
            return null;
        }

        if ($element->getIsDraft() || $element->getIsUnpublishedDraft() || $element->getIsRevision()) {
            $this->logExportDebug('GEO export skipped: draft/revision element');
            return null;
        }

        if ($element->getStatus() !== 'live') {
            $this->logExportDebug('GEO export skipped: element status not live');
            return null;
        }

        if (SeoFieldReader::isNoIndexFor($element)) {
            $this->logExportDebug('GEO export skipped: noindex is set');
            return null;
        }

        $aiMarkdown = SeoFieldReader::readAiMarkdownFor($element);
        if ($aiMarkdown->enabled === AiMarkdownOverride::ENABLED_EXCLUDE) {
            $this->logExportDebug('GEO export skipped: per-element override = exclude');
            return null;
        }

        if ($aiMarkdown->enabled !== AiMarkdownOverride::ENABLED_INCLUDE && !$this->isElementEligible($element, $settings)) {
            $this->logExportDebug('GEO export skipped: element is not eligible (allowlist or commerce gate)');
            return null;
        }

        $body = $settings->geoMarkdownFullPageRender
            ? $this->buildFromRenderedTemplate($element, $settings->geoMarkdownExcludedClasses)
            : $this->buildFromBodyField($element, $settings);

        if ($settings->geoMarkdownExcerptLength !== null && $settings->geoMarkdownExcerptLength > 0) {
            $body = $this->truncateBody($body, $settings->geoMarkdownExcerptLength);
        }

        $title = trim((string) $element->title);
        $heading = $title !== '' ? '# ' . $title . "\n\n" : '';
        $content = $heading . trim($body);

        // Token estimate of the body lets agents size the fetch against a context
        // window before requesting it; emitted as a front-matter key (and as an
        // X-Token-Estimate response header by the controller).
        $tokens = Plugin::$plugin->tokenEstimator->estimate($content);
        $front = $this->buildFrontMatter($element, $settings, $tokens);
        $markdown = $front . $content;

        $this->logExportDebug(sprintf(
            'GEO export built for elementId=%d siteId=%d chars=%d',
            (int) $element->id,
            (int) $element->siteId,
            strlen($markdown),
        ));
        return $markdown;
    }

    /**
     * Per-request export skip/build diagnostics fire on every Markdown
     * negotiation, so — like the meta-resolver and Server-Timing diagnostics —
     * they're gated behind `BEACON_META_DEBUG=1` or Craft's devMode to keep the
     * production log quiet under bot traffic. Genuine failures still log at
     * warning level, ungated.
     */
    private function logExportDebug(string $message): void
    {
        if (Craft::$app === null) {
            return;
        }
        if (getenv('BEACON_META_DEBUG') === '1' || Craft::$app->getConfig()->getGeneral()->devMode) {
            Craft::info($message, 'beacon');
        }
    }

    /**
     * @param list<string> $excludedClasses
     */
    private function buildFromRenderedTemplate(ElementInterface $element, array $excludedClasses): string
    {
        $html = $this->renderElementHtml($element);
        if ($html === null) {
            return $this->buildFromBodyField($element, Plugin::$plugin->settings->get());
        }

        $stripper = $this->chromeStripper();
        $html = $stripper->stripYiiBlockMarkers($html);
        $html = $stripper->extractMarkedContent($html);
        if ($excludedClasses !== []) {
            $html = $stripper->stripElementsWithClasses($html, $excludedClasses);
        }

        return $stripper->htmlToMarkdown($html);
    }

    public function renderElementHtml(ElementInterface $element): ?string
    {
        return ElementHtmlRenderer::render($element);
    }

    private function buildFromBodyField(ElementInterface $element, \anvildev\beacon\models\Settings $settings): string
    {
        $bodyField = trim($settings->geoMarkdownBodyFieldHandle) ?: 'body';
        $body = $this->resolveBodyText($element, $bodyField) ?? '';
        if ($body === '' && $settings->geoMarkdownExcerptFallbackToDescription) {
            $body = SeoFieldReader::readDescriptionFor($element) ?? '';
        }
        return $body;
    }

    /**
     * Layers Site → Section → Element → per-element SEO field front matter.
     * The SEO field override wins even over auto-derived keys (title, canonical, lastUpdated).
     */
    private function buildFrontMatter(ElementInterface $element, \anvildev\beacon\models\Settings $settings, int $tokens = 0): string
    {
        return GeoMarkdownFrontMatter::render(GeoMarkdownFrontMatter::mergeLayers(
            $tokens > 0 ? ['tokens' => $tokens] : [],
            $settings->geoMarkdownFrontMatterDefaults,
            $this->sectionFrontMatter($element),
            $this->elementFrontMatter($element),
            SeoFieldReader::readAiMarkdownFor($element)->customFrontMatter,
        ));
    }

    /**
     * @return array<string,scalar|null>
     */
    private function elementFrontMatter(ElementInterface $element): array
    {
        $url = $element->getUrl();
        return array_filter([
            'title' => (string) $element->title,
            'canonical' => is_string($url) ? $url : '',
            'lastUpdated' => $element->dateUpdated?->format('c') ?? '',
        ], static fn(string|int|float|bool|null $v): bool => $v !== '' && $v !== null);
    }

    /**
     * @return array<string,scalar|null>
     */
    private function sectionFrontMatter(ElementInterface $element): array
    {
        if (!$element instanceof Entry) {
            return [];
        }
        $handle = $this->sectionHandle($element->getSection());
        if ($handle === '') {
            return [];
        }
        $sitemapSettings = Plugin::$plugin->siteSettings->getSitemap((int) $element->siteId);
        return $sitemapSettings->geoMarkdownFrontMatter[$handle] ?? [];
    }

    /**
     * Entry: section allowlist (empty = all). Product: Commerce eligibility gate.
     * Other element types are always eligible as an extension point.
     */
    private function isElementEligible(ElementInterface $element, \anvildev\beacon\models\Settings $settings): bool
    {
        if ($element instanceof Entry) {
            return $this->isAllowedSection($element, $settings->geoMarkdownSectionAllowlist);
        }

        if (class_exists('craft\\commerce\\elements\\Product') && $element instanceof \craft\commerce\elements\Product) {
            return \anvildev\beacon\integrations\CommerceIntegration::isMarkdownEligible();
        }

        return true;
    }

    private function truncateBody(string $body, int $maxChars): string
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) <= $maxChars) {
            return $body;
        }
        $slice = trim(mb_substr($body, 0, $maxChars));
        $lastSpace = mb_strrpos($slice, ' ');
        if ($lastSpace !== false && $lastSpace > (int) floor($maxChars * 0.6)) {
            $slice = mb_substr($slice, 0, $lastSpace);
        }
        return rtrim($slice, " \t\n\r\0\x0B.,;:") . '...';
    }

    private function resolveBodyText(ElementInterface $element, string $primaryHandle): ?string
    {
        $layout = $element->getFieldLayout();
        foreach (array_unique(array_filter([$primaryHandle, 'body', 'content', 'articleBody', 'description'])) as $handle) {
            if ($layout?->getFieldByHandle($handle) === null) {
                continue;
            }
            $value = $element->getFieldValue($handle);
            if ($value === null || $value === '') {
                continue;
            }
            if (is_string($value)) {
                return trim($value);
            }
            if ($value instanceof \Twig\Markup) {
                return $this->chromeStripper()->flattenHtml((string) $value);
            }
        }

        return null;
    }

    /**
     * Section gate for entries. Empty allowlist means "all sections are eligible".
     *
     * @param list<string> $allowlist
     */
    private function isAllowedSection(Entry $entry, array $allowlist): bool
    {
        if ($allowlist === []) {
            return true;
        }
        $handle = $this->sectionHandle($entry->getSection());
        return $handle !== '' && in_array($handle, $allowlist, true);
    }

    /**
     * Section handle for an element's section, or '' when the section is
     * missing or carries no handle.
     */
    private function sectionHandle(?Section $section): string
    {
        return $section?->handle !== null ? trim($section->handle) : '';
    }
}
