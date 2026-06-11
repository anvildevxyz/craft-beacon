<?php

namespace anvildev\beacon\helpers;

use anvildev\beacon\fields\SeoFieldInterface;
use anvildev\beacon\models\AiMarkdownOverride;
use craft\base\ElementInterface;

/**
 * Reads the Beacon SEO field's value off an element without depending on the
 * concrete field class, keeping the service layer decoupled from the field's
 * render path. The field is located via {@see SeoFieldInterface}.
 */
final class SeoFieldReader
{
    /**
     * Returns the Beacon SEO field's array value for an element, or null when
     * the element's layout has no Beacon SEO field.
     *
     * @return array<string,mixed>|null
     */
    public static function readValueFor(ElementInterface $element): ?array
    {
        $layout = $element->getFieldLayout();
        if ($layout === null) {
            return null;
        }
        foreach ($layout->getCustomFields() as $field) {
            if ($field instanceof SeoFieldInterface) {
                $value = $element->getFieldValue($field->handle);
                return is_array($value) ? $value : null;
            }
        }
        return null;
    }

    public static function isNoIndexFor(ElementInterface $element): bool
    {
        $value = self::readValueFor($element);
        return is_array($value) && !empty($value['robots']['noindex']);
    }

    /**
     * Returns the element's canonical URL when it is a publicly indexable
     * surface, or null when it has no URL or is marked noindex. Single source
     * of truth for "should this element appear in sitemaps/feeds/llms.txt".
     */
    public static function indexableUrl(ElementInterface $element): ?string
    {
        $url = $element->getUrl();
        if ($url === null || $url === '' || self::isNoIndexFor($element)) {
            return null;
        }
        return $url;
    }

    /**
     * Best-effort headline for feeds/sitemaps: native element title, then the
     * Beacon SEO field's title override, then slug.
     */
    public static function headlineFor(ElementInterface $element): string
    {
        if (($title = trim((string) $element->title)) !== '') {
            return $title;
        }
        $value = self::readValueFor($element);
        if (is_array($value) && is_string($value['title'] ?? null) && ($title = trim($value['title'])) !== '') {
            return $title;
        }
        return trim((string) ($element->slug ?? ''));
    }

    public static function readDescriptionFor(ElementInterface $element): ?string
    {
        $value = self::readValueFor($element);
        if (!is_array($value) || !is_string($value['description'] ?? null)) {
            return null;
        }
        $description = trim($value['description']);
        return $description !== '' ? $description : null;
    }

    public static function readAiMarkdownFor(ElementInterface $element): AiMarkdownOverride
    {
        $value = self::readValueFor($element);
        $group = is_array($value) && is_array($value['aiMarkdown'] ?? null) ? $value['aiMarkdown'] : [];

        return AiMarkdownOverride::fromSeoFieldGroup($group);
    }
}
