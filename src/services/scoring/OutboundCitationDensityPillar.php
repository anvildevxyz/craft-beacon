<?php

namespace anvildev\beacon\services\scoring;

use anvildev\beacon\enums\GeoScorePillar;
use anvildev\beacon\models\GeoPillarScore;
use anvildev\beacon\services\scoring\heuristics\DomainMatcher;
use Craft;

/**
 * Scores outbound-citation density weighted by source authority. Distinct
 * from {@see FactDensityPillar}, which rewards any outbound source regardless
 * of tier. Tier-1 sources count at 1.0, tier-2 at 0.6; raw density is capped
 * at 5 weighted citations per 1,000 words.
 *
 * The bundled authority list lives at `src/data/authority-domains.json`.
 * Operators override via {@see \anvildev\beacon\models\Settings::$geoScoreAuthorityDomainOverrides}.
 */
final class OutboundCitationDensityPillar implements PillarComputerInterface
{
    private const SCALE_PER_WORDS = 1000;
    private const RAW_CAP = 5;

    public function __construct(
        private readonly AuthorityDomainRegistry $registry = new AuthorityDomainRegistry(),
        private readonly DomainMatcher $matcher = new DomainMatcher(),
    ) {
    }

    public function pillar(): GeoScorePillar
    {
        return GeoScorePillar::OutboundCitationDensity;
    }

    public function compute(PillarContext $ctx): GeoPillarScore
    {
        $ast = $ctx->ast();
        if ($ast === []) {
            return new GeoPillarScore(
                pillar: $this->pillar(),
                score: 0,
                band: GeoPillarScore::BAND_STALE,
                notes: [Craft::t('beacon', 'geo.pillar.outboundCitation.no.content.found.score')],
                debug: ['totalWords' => 0, 'tier1' => 0, 'tier2' => 0, 'unclassified' => 0],
            );
        }

        $totalWords = 0;
        $tier1 = 0;
        $tier2 = 0;
        $unclassifiedHosts = [];
        foreach ($ast as $node) {
            if ($node->type === ContentNode::TYPE_LINK) {
                if ($node->isInternal) {
                    continue;
                }
                $host = $this->matcher->hostFrom((string) $node->href);
                if ($host === '') {
                    continue;
                }
                $tier = $this->registry->classify($host);
                if ($tier === 1) {
                    $tier1++;
                } elseif ($tier === 2) {
                    $tier2++;
                } else {
                    $unclassifiedHosts[$host] = true;
                }
                continue;
            }
            $totalWords += $node->wordCount;
        }

        $unclassifiedCount = count($unclassifiedHosts);

        if ($totalWords < 50) {
            return new GeoPillarScore(
                pillar: $this->pillar(),
                score: 1,
                band: GeoPillarScore::BAND_STALE,
                notes: [Craft::t('beacon', 'geo.pillar.outboundCitation.content.too.short.words.score', ['words' => $totalWords])],
                debug: ['totalWords' => $totalWords, 'tier1' => $tier1, 'tier2' => $tier2, 'unclassified' => $unclassifiedCount],
            );
        }

        $raw = ($tier1 * 1.0) + ($tier2 * 0.6);
        $per1k = ($raw * self::SCALE_PER_WORDS) / max(1, $totalWords);
        $clamped = min(self::RAW_CAP, max(0.0, $per1k));
        $score = GeoPillarScore::clampScore((int) round($clamped * 2));

        return new GeoPillarScore(
            pillar: $this->pillar(),
            score: $score,
            band: GeoPillarScore::bandFor($score),
            notes: $this->buildNotes($score, $tier1, $tier2, $unclassifiedCount, $unclassifiedHosts),
            debug: [
                'totalWords' => $totalWords,
                'tier1' => $tier1,
                'tier2' => $tier2,
                'unclassified' => $unclassifiedCount,
                'per1k' => round($per1k, 3),
            ],
        );
    }

    /**
     * @param array<string,bool> $unclassifiedHosts
     * @return list<string>
     */
    private function buildNotes(int $score, int $tier1, int $tier2, int $unclassifiedCount, array $unclassifiedHosts): array
    {
        if ($score >= 8) {
            return [];
        }
        $notes = [];

        if ($tier1 === 0 && $tier2 === 0) {
            $notes[] = Craft::t('beacon', 'geo.pillar.outboundCitation.no.authoritative.sources');
        } else {
            $notes[] = Craft::t(
                'beacon',
                'geo.pillar.outboundCitation.low.authority.density',
                ['t1' => $tier1, 't2' => $tier2],
            );
        }

        if ($unclassifiedCount > 0) {
            $notes[] = Craft::t(
                'beacon',
                'geo.pillar.outboundCitation.unclassified.hosts',
                ['n' => $unclassifiedCount, 'sample' => implode(', ', array_slice(array_keys($unclassifiedHosts), 0, 3))],
            );
        }

        return $notes;
    }
}
