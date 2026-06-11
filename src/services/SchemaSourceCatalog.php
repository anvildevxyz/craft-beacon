<?php

namespace anvildev\beacon\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\Assets;
use craft\fields\BaseRelationField;
use craft\fields\Date;
use craft\fields\Number;
use craft\fields\PlainText;
use yii\base\Component;

/**
 * Per-entry catalogue of value sources offered to the SEO field's schema
 * picker. Sources fall into three groups, each one rendered in its own
 * <optgroup> in the UI:
 *
 *  - native entry attributes (title, slug, postDate, dateUpdated, url)
 *  - custom fields from the entry layout (handle + label + a stable hint
 *    about the field type so the JS can colour the row)
 *  - SEO field paths (seo.title, seo.description, seo.canonical, seo.openGraph.image)
 *
 * Each row carries a `token` string ready to drop into the existing template
 * format (`{entry.title}`, `{seo.description}`) — that keeps the persistence
 * format and the {@see ExpressionEvaluator} hardening contract unchanged.
 *
 * @phpstan-type Source array{
 *     group: 'entry'|'field'|'seo',
 *     token: string,
 *     label: string,
 *     hint: string,
 * }
 */
class SchemaSourceCatalog extends Component
{
    /**
     * @return list<Source>
     */
    public function forEntry(?ElementInterface $entry): array
    {
        $sources = [
            ['group' => 'entry', 'token' => '{entry.title}', 'label' => Craft::t('beacon', 'Entry title'), 'hint' => Craft::t('beacon', 'string')],
            ['group' => 'entry', 'token' => '{entry.slug}', 'label' => Craft::t('beacon', 'Slug'), 'hint' => Craft::t('beacon', 'string')],
            ['group' => 'entry', 'token' => '{entry.postDate}', 'label' => Craft::t('beacon', 'Post date'), 'hint' => Craft::t('beacon', 'datetime')],
            ['group' => 'entry', 'token' => '{entry.dateUpdated}', 'label' => Craft::t('beacon', 'Last updated'), 'hint' => Craft::t('beacon', 'datetime')],
            ['group' => 'entry', 'token' => '{entry.url}', 'label' => Craft::t('beacon', 'Public URL'), 'hint' => Craft::t('beacon', 'url')],
            ['group' => 'seo', 'token' => '{seo.title}', 'label' => Craft::t('beacon', 'SEO title'), 'hint' => Craft::t('beacon', 'string')],
            ['group' => 'seo', 'token' => '{seo.description}', 'label' => Craft::t('beacon', 'SEO description'), 'hint' => Craft::t('beacon', 'string')],
            ['group' => 'seo', 'token' => '{seo.canonical}', 'label' => Craft::t('beacon', 'Canonical URL'), 'hint' => Craft::t('beacon', 'url')],
            ['group' => 'seo', 'token' => '{seo.openGraph.image}', 'label' => Craft::t('beacon', 'Social image'), 'hint' => Craft::t('beacon', 'url')],
        ];

        if ($entry instanceof Entry) {
            $sources = [...$sources, ...$this->customFieldSources($entry)];
        }

        return $sources;
    }

    /**
     * @return list<Source>
     */
    private function customFieldSources(Entry $entry): array
    {
        $layout = $entry->getFieldLayout();
        if ($layout === null) {
            return [];
        }

        $rows = [];
        foreach ($layout->getCustomFields() as $field) {
            $handle = (string) $field->handle;
            if ($handle === '') {
                continue;
            }
            $rows[] = [
                'group' => 'field',
                'token' => '{entry.' . $handle . '}',
                'label' => (string) $field->name . ' (' . $handle . ')',
                'hint' => $this->fieldHint($field),
            ];
        }

        return $rows;
    }

    private function fieldHint(FieldInterface $field): string
    {
        return match (true) {
            $field instanceof Assets => Craft::t('beacon', 'asset'),
            $field instanceof Date => Craft::t('beacon', 'datetime'),
            $field instanceof Number => Craft::t('beacon', 'number'),
            $field instanceof PlainText => Craft::t('beacon', 'string'),
            $field instanceof BaseRelationField => Craft::t('beacon', 'relation'),
            default => Craft::t('beacon', 'mixed'),
        };
    }
}
