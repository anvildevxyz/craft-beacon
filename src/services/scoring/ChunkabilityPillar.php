<?php

namespace anvildev\beacon\services\scoring;

use anvildev\beacon\enums\GeoScorePillar;
use anvildev\beacon\models\GeoPillarScore;
use Craft;

/**
 * Scores how well each H2 section opens with a self-contained 40–75-word
 * answer paragraph before the next H2.
 */
final class ChunkabilityPillar implements PillarComputerInterface
{
    private const MIN_WORDS = 40;
    private const MAX_WORDS = 75;

    public function pillar(): GeoScorePillar
    {
        return GeoScorePillar::Chunkability;
    }

    public function compute(PillarContext $ctx): GeoPillarScore
    {
        $sections = $this->collectH2Sections($ctx->ast());

        if ($sections === []) {
            return new GeoPillarScore(
                pillar: $this->pillar(),
                score: 3,
                band: GeoPillarScore::BAND_LOW,
                notes: [Craft::t('beacon', 'geo.pillar.chunkability.no.h2.sections.found.break')],
                debug: ['totalSections' => 0, 'inRange' => 0],
            );
        }

        $inRange = 0;
        $shortLeads = [];
        $longLeads = [];
        $stackedHeadings = [];

        foreach ($sections as $section) {
            $lead = $section['lead'];
            if ($lead === null) {
                $stackedHeadings[] = $section['heading']->text;
                continue;
            }
            if ($lead->wordCount >= self::MIN_WORDS && $lead->wordCount <= self::MAX_WORDS) {
                $inRange++;
            } elseif ($lead->wordCount < self::MIN_WORDS) {
                $shortLeads[] = ['heading' => $section['heading']->text, 'words' => $lead->wordCount];
            } else {
                $longLeads[] = ['heading' => $section['heading']->text, 'words' => $lead->wordCount];
            }
        }

        $totalSections = count($sections);
        $score = GeoPillarScore::scoreFromRatio($inRange / $totalSections);

        return new GeoPillarScore(
            pillar: $this->pillar(),
            score: $score,
            band: GeoPillarScore::bandFor($score),
            notes: $this->buildNotes($shortLeads, $longLeads, $stackedHeadings),
            debug: [
                'totalSections' => $totalSections,
                'inRange' => $inRange,
                'shortLeads' => $shortLeads,
                'longLeads' => $longLeads,
                'stackedHeadings' => $stackedHeadings,
            ],
        );
    }

    /**
     * @param list<ContentNode> $ast
     * @return list<array{heading: ContentNode, lead: ?ContentNode}>
     */
    private function collectH2Sections(array $ast): array
    {
        $sections = [];
        $current = null;
        $leadAssigned = false;

        foreach ($ast as $node) {
            if ($node->type === ContentNode::TYPE_HEADING && $node->level === 2) {
                if ($current !== null) {
                    $sections[] = $current;
                }
                $current = ['heading' => $node, 'lead' => null];
                $leadAssigned = false;
                continue;
            }

            if ($current === null) {
                continue;
            }

            // H3+ before any paragraph = stacked-subheading; the H2 has no self-contained answer.
            if ($node->type === ContentNode::TYPE_HEADING && $node->level >= 3 && !$leadAssigned) {
                $leadAssigned = true;
                continue;
            }

            if (!$leadAssigned && $node->type === ContentNode::TYPE_PARAGRAPH) {
                $current['lead'] = $node;
                $leadAssigned = true;
            }
        }

        if ($current !== null) {
            $sections[] = $current;
        }
        return $sections;
    }

    /**
     * @param list<array{heading: string, words: int}> $shortLeads
     * @param list<array{heading: string, words: int}> $longLeads
     * @param list<string> $stackedHeadings
     * @return list<string>
     */
    private function buildNotes(array $shortLeads, array $longLeads, array $stackedHeadings): array
    {
        $notes = [];

        if ($shortLeads !== []) {
            $first = $shortLeads[0];
            $notes[] = Craft::t(
                'beacon',
                'geo.pillar.chunkability.short.lead',
                ['n' => count($shortLeads), 'heading' => $first['heading'], 'words' => $first['words']],
            );
        }

        if ($longLeads !== []) {
            $first = $longLeads[0];
            $notes[] = Craft::t(
                'beacon',
                'geo.pillar.chunkability.long.lead',
                ['n' => count($longLeads), 'heading' => $first['heading'], 'words' => $first['words']],
            );
        }

        if ($stackedHeadings !== []) {
            $notes[] = Craft::t(
                'beacon',
                'geo.pillar.chunkability.stacked.headings',
                ['n' => count($stackedHeadings), 'sample' => implode('", "', array_slice($stackedHeadings, 0, 3))],
            );
        }

        return $notes;
    }
}
