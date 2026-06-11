<?php

namespace anvildev\beacon\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\web\View;
use Throwable;

/**
 * Renders an element's site Twig template to raw HTML. Shared by GEO Markdown
 * export and the GEO score content walker so neither pipeline depends on the
 * other through {@see \anvildev\beacon\Plugin}.
 */
final class ElementHtmlRenderer
{
    public static function render(ElementInterface $element): ?string
    {
        $template = self::resolveTemplate($element);
        if ($template === null || $template === '') {
            return null;
        }

        try {
            return Craft::$app->getView()->renderTemplate(
                $template,
                self::renderVariables($element),
                View::TEMPLATE_MODE_SITE,
            );
        } catch (Throwable $e) {
            Craft::warning(
                sprintf('GEO export: full-page render failed for elementId=%d: %s', (int) $element->id, $e->getMessage()),
                'beacon',
            );
            return null;
        }
    }

    private static function resolveTemplate(ElementInterface $element): ?string
    {
        if ($element instanceof Entry) {
            $section = $element->getSection();
            $siteSettings = is_object($section) ? ($section->getSiteSettings()[$element->siteId] ?? null) : null;
            return is_object($siteSettings) && isset($siteSettings->template) ? (string) $siteSettings->template : null;
        }

        if (method_exists($element, 'getType')) {
            $type = $element->getType();
            $siteSettings = (is_object($type) && method_exists($type, 'getSiteSettings'))
                ? ($type->getSiteSettings()[$element->siteId] ?? null)
                : null;
            if (is_object($siteSettings) && isset($siteSettings->template)) {
                return (string) $siteSettings->template;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private static function renderVariables(ElementInterface $element): array
    {
        return match (true) {
            $element instanceof Entry => ['entry' => $element, 'entryType' => $element->getType()],
            class_exists('craft\\commerce\\elements\\Product') && $element instanceof \craft\commerce\elements\Product
                => ['product' => $element, 'productType' => $element->getType()],
            default => ['element' => $element],
        };
    }
}
