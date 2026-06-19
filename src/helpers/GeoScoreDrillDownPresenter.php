<?php

namespace anvildev\beacon\helpers;

use anvildev\beacon\models\GeoPillarScore;
use anvildev\beacon\models\GeoScore;
use Craft;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;

/**
 * Builds the drill-down CP view model: composite hero data, focus pillars,
 * and note rows with optional deep links.
 */
final class GeoScoreDrillDownPresenter
{
    /**
     * @return array{
     *   compositeBand: string,
     *   compositeBandLabel: string,
     *   computedAtFormatted: string,
     *   weakest: ?array{label: string, score: int, band: string},
     *   focusPillars: list<array{pillar: GeoPillarScore, notes: list<array{text: string, url: ?string}>, scorePct: int}>,
     *   strongPillars: list<array{label: string, score: int}>,
     *   allPillars: list<array{pillar: GeoPillarScore, notes: list<array{text: string, url: ?string}>, scorePct: int, isFocus: bool}>
     * }
     */
    public static function prepare(GeoScore $score, ?Entry $element): array
    {
        $sorted = array_values($score->pillars);
        usort($sorted, fn(GeoPillarScore $a, GeoPillarScore $b) => $a->score <=> $b->score);

        $weakestPillar = null;
        foreach ($sorted as $pillar) {
            if ($weakestPillar === null || $pillar->score < $weakestPillar->score) {
                $weakestPillar = $pillar;
            }
        }

        $focusPillars = [];
        $strongPillars = [];
        $allPillars = [];

        foreach ($sorted as $pillar) {
            $row = self::pillarRow($pillar, $element);
            $isFocus = $pillar->notes !== []
                && in_array($pillar->band, [GeoPillarScore::BAND_LOW, GeoPillarScore::BAND_STALE], true);
            $row['isFocus'] = $isFocus;
            $allPillars[] = $row;

            if ($isFocus && count($focusPillars) < 2) {
                $focusPillars[] = $row;
            } elseif ($pillar->band === GeoPillarScore::BAND_TOP && $pillar->notes === []) {
                $strongPillars[] = [
                    'label' => $pillar->label(),
                    'score' => $pillar->score,
                ];
            }
        }

        $band = GeoScoreCompositeBand::forScore($score->score);
        $computedAt = DateTimeHelper::toDateTime($score->computedAt);

        return [
            'compositeBand' => $band,
            'compositeBandLabel' => GeoScoreCompositeBand::label($band),
            'computedAtFormatted' => $computedAt !== false ? $computedAt->format('Y-m-d H:i') : '',
            'weakest' => $weakestPillar !== null ? [
                'label' => $weakestPillar->label(),
                'score' => $weakestPillar->score,
                'band' => $weakestPillar->band,
            ] : null,
            'focusPillars' => $focusPillars,
            'strongPillars' => $strongPillars,
            'allPillars' => $allPillars,
        ];
    }

    /**
     * @return array{pillar: GeoPillarScore, notes: list<array{text: string, url: ?string}>, scorePct: int}
     */
    private static function pillarRow(GeoPillarScore $pillar, ?Entry $element): array
    {
        $notes = [];
        foreach ($pillar->notes as $note) {
            $url = GeoScoreDrillDownNoteLink::urlForNote($note, $element);
            $notes[] = [
                'text' => $note,
                'url' => $url,
                'linkLabel' => self::linkLabel($url),
            ];
        }

        return [
            'pillar' => $pillar,
            'notes' => $notes,
            'scorePct' => max(0, min(100, $pillar->score * 10)),
        ];
    }

    private static function linkLabel(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        if (str_contains($url, '/authors')) {
            return Craft::t('beacon', 'geoScore.drillDown.note.link.authors');
        }
        if (str_contains($url, '/settings/')) {
            return Craft::t('beacon', 'geoScore.drillDown.note.link.settings');
        }

        return Craft::t('beacon', 'geoScore.drillDown.note.link.edit.entry');
    }
}
