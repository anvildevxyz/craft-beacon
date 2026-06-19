<?php

namespace anvildev\beacon\services;

use anvildev\beacon\Plugin;
use anvildev\beacon\services\ai\AiPromptBuilder;
use anvildev\beacon\services\ai\AiResponseParser;
use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use yii\base\Component;

/**
 * Orchestrates AI-assisted content generation for the entry editor.
 *
 * Pulls chrome-free entry content (via {@see GeoMarkdownExportService}) plus
 * GEO-score context, builds a task-specific prompt, and runs it through
 * {@see AiClient}. Returns plain values the CP layer hands to the editor —
 * nothing here writes to an element.
 */
class AiContentService extends Component
{
    private AiPromptBuilder $prompts;

    public function init(): void
    {
        parent::init();
        $this->prompts = new AiPromptBuilder();
    }

    public function generateTitle(Entry $entry): string
    {
        $p = $this->prompts->metaTitle((string) $entry->title, $this->contentFor($entry), $this->contextFor($entry));
        return AiResponseParser::oneLine($this->client()->complete($p['system'], $p['user']));
    }

    public function generateDescription(Entry $entry): string
    {
        $p = $this->prompts->metaDescription((string) $entry->title, $this->contentFor($entry), $this->contextFor($entry));
        return AiResponseParser::oneLine($this->client()->complete($p['system'], $p['user']));
    }

    public function generateSummary(Entry $entry): string
    {
        $p = $this->prompts->summary((string) $entry->title, $this->contentFor($entry), $this->contextFor($entry));
        return AiResponseParser::oneLine($this->client()->complete($p['system'], $p['user']));
    }

    public function generateAltText(Asset $asset, ?Entry $entry = null): string
    {
        $p = $this->prompts->altText($asset->filename, $entry !== null ? (string) $entry->title : '');
        return AiResponseParser::oneLine($this->client()->complete($p['system'], $p['user']));
    }

    /**
     * Generate FAQ Q&A pairs grounded in the entry content. The matching
     * `FAQPage` JSON-LD is built from these by {@see self::faqSchema()}.
     *
     * @return list<array{question: string, answer: string}>
     */
    public function generateFaq(Entry $entry): array
    {
        $p = $this->prompts->faq((string) $entry->title, $this->contentFor($entry), $this->contextFor($entry));
        return AiResponseParser::faq($this->client()->complete($p['system'], $p['user']));
    }

    /**
     * Build a schema.org `FAQPage` node from Q&A pairs, ready to merge into the
     * entry's JSON-LD graph.
     *
     * @param list<array{question: string, answer: string}> $faq
     * @return array{'@context': string, '@type': string, mainEntity: list<array{'@type': string, name: string, acceptedAnswer: array{'@type': string, text: string}}>}
     */
    public function faqSchema(array $faq): array
    {
        $mainEntity = [];
        foreach ($faq as $item) {
            $mainEntity[] = [
                '@type' => 'Question',
                'name' => $item['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $item['answer'],
                ],
            ];
        }
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $mainEntity,
        ];
    }

    private function client(): AiClient
    {
        return Plugin::$plugin->aiClient;
    }

    private function contentFor(Entry $entry): string
    {
        try {
            $markdown = Plugin::$plugin->geoMarkdownExport->exportElement($entry);
        } catch (\Throwable $e) {
            Craft::warning('AI content: markdown export failed, falling back to title: ' . $e->getMessage(), 'beacon');
            $markdown = null;
        }
        return (is_string($markdown) && trim($markdown) !== '') ? $markdown : (string) $entry->title;
    }

    /**
     * @return array{section: string, geoScore: int|null, weakPillars: list<string>}
     */
    private function contextFor(Entry $entry): array
    {
        $score = null;
        $weak = [];
        try {
            if ($entry->id) {
                $geoScore = Plugin::$plugin->geoScore->forElement((int) $entry->id, (int) $entry->siteId);
                if ($geoScore !== null) {
                    $score = $geoScore->score;
                    $weakest = $geoScore->weakestPillar();
                    if ($weakest !== null) {
                        $weak[] = $weakest->value;
                    }
                }
            }
        } catch (\Throwable $e) {
            Craft::warning('AI content: GEO context lookup failed: ' . $e->getMessage(), 'beacon');
        }

        return [
            'section' => $entry->getSection()?->name ?? '',
            'geoScore' => $score,
            'weakPillars' => $weak,
        ];
    }
}
