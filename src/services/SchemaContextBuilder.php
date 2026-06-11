<?php

namespace anvildev\beacon\services;

use anvildev\beacon\fields\SeoFieldInterface;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\fields\Assets;
use craft\fields\BaseRelationField;
use yii\base\Component;

/**
 * Builds the array context handed to {@see SchemaService::render()} and
 * {@see ExpressionEvaluator::interpolate()}. Centralised here so the SEO
 * field's "Suggest mapping" button and the front-end render path stay in
 * agreement about what `{entry.<handle>}` actually returns.
 *
 * The output is array-only — see ExpressionEvaluator's class docblock for
 * the security rationale (object-property traversal is intentionally
 * unreachable, so editors can't reach `entry.password` or call methods
 * through magic accessors).
 *
 * Asset/relation fields are eagerly flattened so authors can write
 * `{entry.heroImage.0.url}` without the evaluator stumbling over Element
 * instances.
 */
class SchemaContextBuilder extends Component
{
    /**
     * @param array<string,mixed> $seoFieldValue  Pre-resolved Beacon SEO field array (title/description/etc.).
     * @return array<string,mixed>
     */
    public function build(?ElementInterface $entry, array $seoFieldValue): array
    {
        return [
            'title' => $entry !== null ? (string) ($entry->title ?? '') : '',
            'seo' => $seoFieldValue,
            'entry' => $entry !== null ? $this->entryToArray($entry) : [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function entryToArray(ElementInterface $entry): array
    {
        $attrs = $entry->getAttributes();

        try {
            $url = $entry->getUrl();
        } catch (\yii\base\InvalidConfigException) {
            $url = null;
        }
        if (is_string($url) && $url !== '') {
            $attrs['url'] = $url;
        }

        try {
            $site = $entry instanceof \craft\base\Element ? $entry->getSite() : null;
        } catch (\yii\base\InvalidConfigException) {
            $site = null;
        }
        if ($site !== null) {
            $attrs['site'] = [
                'handle' => (string) $site->handle,
                'name' => (string) $site->name,
                'language' => (string) $site->language,
                'baseUrl' => (string) ($site->getBaseUrl() ?? ''),
            ];
        }

        $layout = $entry->getFieldLayout();
        if ($layout === null) {
            return $attrs;
        }

        foreach ($layout->getCustomFields() as $field) {
            $handle = (string) $field->handle;
            if ($handle === '' || $handle === 'beaconSeo' || $field instanceof SeoFieldInterface) {
                continue;
            }
            $attrs[$handle] = $this->fieldValueForContext($entry, $field, $handle);
        }

        return $attrs;
    }

    private function fieldValueForContext(ElementInterface $entry, FieldInterface $field, string $handle): mixed
    {
        if ($field instanceof Assets) {
            return $this->assetsToArray($entry, $handle);
        }

        if ($field instanceof BaseRelationField) {
            return $this->relationsToArray($entry, $handle);
        }

        $value = $entry->getFieldValue($handle);

        if (is_scalar($value) || $value === null) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (is_array($value)) {
            return $value;
        }

        $serialized = $entry->getSerializedFieldValues([$handle]);
        return $serialized[$handle] ?? null;
    }

    /**
     * @return list<array{id:int, url:?string, alt:?string, title:?string, width:?int, height:?int}>
     */
    private function assetsToArray(ElementInterface $entry, string $handle): array
    {
        $value = $entry->getFieldValue($handle);
        if (!is_iterable($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }
            $url = $asset->getUrl();
            $width = $asset->getWidth();
            $height = $asset->getHeight();
            $rows[] = [
                'id' => (int) $asset->id,
                'url' => is_string($url) && $url !== '' ? $url : null,
                'alt' => $asset->alt ?: null,
                'title' => $asset->title ?: null,
                'width' => is_int($width) ? $width : null,
                'height' => is_int($height) ? $height : null,
            ];
        }
        return $rows;
    }

    /**
     * @return list<array{id:int, title:?string, url:?string}>
     */
    private function relationsToArray(ElementInterface $entry, string $handle): array
    {
        $value = $entry->getFieldValue($handle);
        if (!is_iterable($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $related) {
            if (!$related instanceof ElementInterface) {
                continue;
            }
            try {
                $url = $related->getUrl();
            } catch (\yii\base\InvalidConfigException) {
                $url = null;
            }
            $rows[] = [
                'id' => (int) $related->id,
                'title' => is_string($related->title) ? $related->title : null,
                'url' => is_string($url) && $url !== '' ? $url : null,
            ];
        }
        return $rows;
    }
}
