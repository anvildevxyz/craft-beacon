<?php

namespace anvildev\beacon\services\scoring;

use anvildev\beacon\enums\GeoScorePillar;
use anvildev\beacon\models\GeoPillarScore;
use anvildev\beacon\Plugin;
use anvildev\beacon\services\scoring\heuristics\FactDetectors;
use Craft;

/**
 * Scores ratio of citable facts to total word count, normalised against
 * {@see \anvildev\beacon\models\Settings::$geoScoreFactDensityTarget}
 * (default: one fact per 80 words).
 *
 * Facts come from four detectors (see {@see FactDetectors}): numeric and
 * date assertions, outbound citation links (presence only — authority is
 * scored separately by {@see OutboundCitationDensityPillar}), and named entities.
 */
final class FactDensityPillar implements PillarComputerInterface
{
    public function __construct(
        private readonly FactDetectors $detectors = new FactDetectors(),
        private readonly ?int $targetOverride = null,
    ) {
    }

    public function pillar(): GeoScorePillar
    {
        return GeoScorePillar::FactDensity;
    }

    public function compute(PillarContext $ctx): GeoPillarScore
    {
        $target = $this->targetOverride
            ?? max(1, Plugin::$plugin->settings->get()->geoScoreFactDensityTarget);

        $ast = $ctx->ast();
        if ($ast === []) {
            return new GeoPillarScore(
                pillar: $this->pillar(),
                score: 0,
                band: GeoPillarScore::BAND_STALE,
                notes: [Craft::t('beacon', 'geo.pillar.factDensity.no.content.found.score.add')],
                debug: ['totalWords' => 0, 'factCount' => 0, 'target' => $target],
            );
        }

        // Collect prose text from paragraph / list / table nodes — code
        // blocks are deliberately excluded (a code sample's literals aren't
        // citable facts an AI engine quotes as evidence).
        $proseText = '';
        $totalWords = 0;
        $links = [];
        foreach ($ast as $node) {
            if ($node->type === ContentNode::TYPE_LINK) {
                $links[] = ['href' => (string) $node->href, 'isInternal' => $node->isInternal];
                continue;
            }
            if (!in_array($node->type, [ContentNode::TYPE_PARAGRAPH, ContentNode::TYPE_LIST, ContentNode::TYPE_TABLE, ContentNode::TYPE_HEADING], true)) {
                continue;
            }
            $proseText .= ' ' . $node->text;
            $totalWords += $node->wordCount;
        }

        if ($totalWords < 50) {
            // Too short for density to be meaningful — bottom band, but
            // with a "too short" note rather than a numeric gap.
            return new GeoPillarScore(
                pillar: $this->pillar(),
                score: 1,
                band: GeoPillarScore::BAND_STALE,
                notes: [Craft::t('beacon', 'geo.pillar.factDensity.content.too.short.words.score', ['words' => $totalWords])],
                debug: ['totalWords' => $totalWords, 'factCount' => 0, 'target' => $target],
            );
        }

        $facts = $this->detectors->countNumericAssertions($proseText)
            + $this->detectors->countDateAssertions($proseText)
            + $this->detectors->countNamedEntityAssertions($proseText)
            + $this->detectors->countCitationLinks($links);

        // ratio of 1.0 = exactly at target (e.g. 5 facts in 400 words at
        // target=80). Clamp at 1.0 so over-dense content doesn't blow past
        // the top band — diminishing returns past 1:target.
        $density = $facts / max(1, $totalWords);
        $ratio = min(1.0, $density * $target);
        $score = GeoPillarScore::scoreFromRatio($ratio);

        $notes = [];
        if ($score < 7) {
            $factsNeededForTop = (int) ceil($totalWords / $target);
            $gap = max(0, $factsNeededForTop - $facts);
            $notes[] = $gap > 0
                ? Craft::t(
                    'beacon',
                    'Fact density {found}:{words} (found {facts} fact(s) in {words} words). Add {gap} more stat(s), date(s), or citation(s) to hit the 1-per-{target}-words target.',
                    [
                        'found' => $facts > 0 ? '1' : '0',
                        'words' => $totalWords,
                        'facts' => $facts,
                        'gap' => $gap,
                        'target' => $target,
                    ],
                )
                : Craft::t(
                    'beacon',
                    'Fact density just below target ({facts} fact(s) in {words} words). Consider tightening prose to lift density.',
                    ['facts' => $facts, 'words' => $totalWords],
                );
        }

        return new GeoPillarScore(
            pillar: $this->pillar(),
            score: $score,
            band: GeoPillarScore::bandFor($score),
            notes: $notes,
            debug: [
                'totalWords' => $totalWords,
                'factCount' => $facts,
                'target' => $target,
                'density' => round($density, 5),
            ],
        );
    }
}
