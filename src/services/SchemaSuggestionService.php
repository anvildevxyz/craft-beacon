<?php

namespace anvildev\beacon\services;

use anvildev\beacon\helpers\SeoFieldReader;
use anvildev\beacon\schemas\SchemaPropertyRegistry;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\fields\Assets;
use craft\fields\Date;
use craft\fields\PlainText;
use yii\base\Component;

/**
 * Proposes a `mapping` array (property => template) for the SEO field's
 * "Suggest mapping" button. Walks the entry's field layout + native attributes,
 * matches them against the {@see SchemaPropertyRegistry}, and returns tokens
 * the {@see ExpressionEvaluator} can already resolve.
 *
 * Conservative on purpose: only emits suggestions backed by an actual entry
 * value or a registry hint, so authors never have to delete obviously-wrong
 * proposals. Editors will still have to fill required properties without an
 * obvious source (e.g. `recipeIngredient`) by hand.
 *
 * @phpstan-import-type PropertyDef from \anvildev\beacon\schemas\SchemaPropertyRegistry
 */
class SchemaSuggestionService extends Component
{
    /**
     * @return array<string, string>
     */
    public function suggest(ElementInterface $entry, string $type): array
    {
        $properties = SchemaPropertyRegistry::forType($type);
        if ($properties === []) {
            return [];
        }

        $sources = $this->entrySources($entry);
        $mapping = [];

        foreach ($properties as $prop) {
            $token = $this->pickTokenForProperty($prop, $sources);
            if ($token !== null) {
                $mapping[$prop['name']] = $token;
            }
        }

        return $mapping;
    }

    /**
     * Entry-agnostic suggestion: proposes a mapping from the registry's `suggest`
     * hints alone. Used by the global schema admin form, where a mapping is being
     * defined for an entry *type* and there may be no representative entry yet.
     * Each hint (e.g. `seo.title`) becomes the matching `{seo.title}` token the
     * ExpressionEvaluator can resolve at render time.
     *
     * @return array<string, string>
     */
    public function suggestForType(string $type): array
    {
        $mapping = [];
        foreach (SchemaPropertyRegistry::forType($type) as $prop) {
            $hint = $prop['suggest'][0] ?? null;
            if (is_string($hint) && $hint !== '') {
                $mapping[$prop['name']] = '{' . $hint . '}';
            }
        }

        return $mapping;
    }

    /**
     * @param PropertyDef $prop
     * @param array<string,string> $sources  Map of "kind" => token (e.g. "title" => "{entry.title}")
     */
    private function pickTokenForProperty(array $prop, array $sources): ?string
    {
        foreach ($prop['suggest'] ?? [] as $hint) {
            $token = $this->tokenForHint($hint, $sources);
            if ($token !== null) {
                return $token;
            }
        }

        return match ($prop['name']) {
            'name', 'headline' => $sources['title'] ?? null,
            'description' => $sources['seoDescription'] ?? null,
            'image' => $sources['firstAsset'] ?? $sources['ogImage'] ?? null,
            'datePublished' => $sources['postDate'] ?? null,
            'dateModified' => $sources['dateUpdated'] ?? null,
            'mainEntityOfPage' => $sources['canonical'] ?? null,
            default => null,
        };
    }

    /**
     * @param array<string,string> $sources
     */
    private function tokenForHint(string $hint, array $sources): ?string
    {
        return match ($hint) {
            'entry.title' => $sources['title'] ?? null,
            'entry.postDate' => $sources['postDate'] ?? null,
            'entry.dateUpdated' => $sources['dateUpdated'] ?? null,
            'entry.site.language' => $sources['siteLanguage'] ?? null,
            'seo.title' => $sources['seoTitle'] ?? null,
            'seo.description' => $sources['seoDescription'] ?? null,
            'seo.canonical' => $sources['canonical'] ?? null,
            'seo.openGraph.image' => $sources['ogImage'] ?? $sources['firstAsset'] ?? null,
            default => null,
        };
    }

    /**
     * Builds a flat lookup of "what value sources actually exist for this
     * entry?" that the property heuristics can read.
     *
     * @return array<string,string>
     */
    private function entrySources(ElementInterface $entry): array
    {
        $sources = [
            'title' => '{entry.title}',
            'dateUpdated' => '{entry.dateUpdated}',
            'canonical' => '{seo.canonical}',
            'seoTitle' => '{seo.title}',
            'seoDescription' => '{seo.description}',
            'ogImage' => '{seo.openGraph.image}',
            'siteLanguage' => '{entry.site.language}',
        ];

        if ($entry instanceof Entry && $entry->postDate !== null) {
            $sources['postDate'] = '{entry.postDate}';
        }

        $firstAssetHandle = null;
        $firstDateHandle = null;
        $firstSummaryHandle = null;

        $layout = $entry->getFieldLayout();
        if ($layout !== null) {
            $summaryHandles = ['summary' => 0, 'excerpt' => 0, 'lead' => 0, 'intro' => 0, 'description' => 0];
            foreach ($layout->getCustomFields() as $field) {
                $handle = (string) $field->handle;
                if ($handle === '') {
                    continue;
                }
                $firstAssetHandle ??= $field instanceof Assets ? $handle : null;
                $firstDateHandle ??= $field instanceof Date ? $handle : null;
                if (
                    $firstSummaryHandle === null
                    && $field instanceof PlainText
                    && isset($summaryHandles[strtolower($handle)])
                ) {
                    $firstSummaryHandle = $handle;
                }
                if ($firstAssetHandle !== null && $firstDateHandle !== null && $firstSummaryHandle !== null) {
                    break;
                }
            }
        }

        if ($firstAssetHandle !== null) {
            $sources['firstAsset'] = '{entry.' . $firstAssetHandle . '.0.url}';
        }
        if ($firstDateHandle !== null && !isset($sources['postDate'])) {
            $sources['postDate'] = '{entry.' . $firstDateHandle . '}';
        }
        if ($firstSummaryHandle !== null) {
            $sources['seoDescription'] = '{entry.' . $firstSummaryHandle . '}';
        }

        if (SeoFieldReader::readDescriptionFor($entry) !== null) {
            $sources['seoDescription'] = '{seo.description}';
        }

        return $sources;
    }

    public function knowsType(string $type): bool
    {
        return SchemaPropertyRegistry::forType($type) !== [];
    }
}
