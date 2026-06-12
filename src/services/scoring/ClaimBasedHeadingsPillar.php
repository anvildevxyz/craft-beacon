<?php

namespace anvildev\beacon\services\scoring;

use anvildev\beacon\enums\GeoScorePillar;
use anvildev\beacon\models\GeoPillarScore;
use Craft;

/**
 * Scores the share of H2/H3 headings phrased as complete statements
 * ("Composer plugins must run before PHP-FPM restarts") rather than
 * noun-phrase topics ("Composer plugins"). Uses {@see HeadingClassifier}.
 */
final class ClaimBasedHeadingsPillar implements PillarComputerInterface
{
    public function pillar(): GeoScorePillar
    {
        return GeoScorePillar::ClaimBasedHeadings;
    }

    public function compute(PillarContext $ctx): GeoPillarScore
    {
        $headings = array_values(array_filter(
            $ctx->ast(),
            static fn(ContentNode $n): bool =>
                $n->type === ContentNode::TYPE_HEADING && in_array($n->level, [2, 3], true),
        ));

        if ($headings === []) {
            return new GeoPillarScore(
                pillar: $this->pillar(),
                score: 3,
                band: GeoPillarScore::BAND_LOW,
                notes: [Craft::t('beacon', 'geo.pillar.claimHeadings.no.h2.h3.subheadings.found')],
                debug: ['totalHeadings' => 0, 'claimCount' => 0],
            );
        }

        $language = $this->siteLanguage($ctx);
        $classifier = new HeadingClassifier($language);
        $claimCount = 0;
        $topicHeadings = [];
        foreach ($headings as $heading) {
            if ($classifier->isClaim($heading->text)) {
                $claimCount++;
            } else {
                $topicHeadings[] = $heading->text;
            }
        }

        $score = GeoPillarScore::scoreFromRatio($claimCount / count($headings));

        $notes = [];
        if ($topicHeadings !== []) {
            $notes[] = Craft::t(
                'beacon',
                '{n} heading(s) read as topics rather than claims, e.g. "{sample}". Rephrase as complete statements (subject + verb).',
                [
                    'n' => count($topicHeadings),
                    'sample' => implode('", "', array_slice($topicHeadings, 0, 3)),
                ],
            );
        }

        return new GeoPillarScore(
            pillar: $this->pillar(),
            score: $score,
            band: GeoPillarScore::bandFor($score),
            notes: $notes,
            debug: [
                'language' => $language,
                'totalHeadings' => count($headings),
                'claimCount' => $claimCount,
                'topicHeadings' => $topicHeadings,
            ],
        );
    }

    private function siteLanguage(PillarContext $ctx): string
    {
        try {
            return $ctx->element->getSite()->language;
        } catch (\yii\base\InvalidConfigException) {
            return Craft::$app->getSites()->getPrimarySite()->language;
        }
    }
}
