<?php

namespace anvildev\beacon\services\scoring;

use anvildev\beacon\enums\GeoScorePillar;
use anvildev\beacon\models\GeoPillarScore;
use anvildev\beacon\Plugin;
use Craft;
use craft\base\ElementInterface;

/**
 * Scores Schema.org entity completeness — the graph traversal AI engines
 * use to disambiguate "who is this". Inspects the configured Organization
 * (or Person, on personal sites) plus any attached Author elements.
 *
 * Components:
 *   - Organization name present                          (2 pts)
 *   - Organization logo asset configured                 (1 pt)
 *   - Organization sameAs[] count
 *       0  → 0 pts
 *       1–2 → 2 pts
 *       3+  → 4 pts
 *   - Author attached to the entry with sameAs[] of own  (3 pts)
 *
 * Total scaled to 0–10. No content walk — settings + author records only.
 */
final class EntityCompletenessPillar implements PillarComputerInterface
{
    public function pillar(): GeoScorePillar
    {
        return GeoScorePillar::EntityCompleteness;
    }

    public function compute(PillarContext $ctx): GeoPillarScore
    {
        $settings = Plugin::$plugin->settings->get();
        $notes = [];
        $points = 0;

        if (is_string($settings->organizationName) && trim($settings->organizationName) !== '') {
            $points += 2;
        } else {
            $notes[] = Craft::t('beacon', 'geo.pillar.entityCompleteness.set.site.organization.name.settings');
        }

        if ($settings->organizationLogoAssetId !== null) {
            $points += 1;
        } else {
            $notes[] = Craft::t('beacon', 'geo.pillar.entityCompleteness.add.organization.logo.asset.richer');
        }

        $sameAsCount = count($settings->sameAsUrls());
        $points += match (true) {
            $sameAsCount >= 3 => 4,
            $sameAsCount >= 1 => 2,
            default => 0,
        };
        if ($sameAsCount < 3) {
            $notes[] = Craft::t(
                'beacon',
                'geo.pillar.entityCompleteness.organization.sameAs.threshold',
                ['count' => $sameAsCount],
            );
        }

        $authorCoverage = $this->scoreAuthorCoverage($ctx->element);
        $points += $authorCoverage['points'];
        if ($authorCoverage['note'] !== null) {
            $notes[] = $authorCoverage['note'];
        }

        $score = GeoPillarScore::clampScore($points);
        return new GeoPillarScore(
            pillar: $this->pillar(),
            score: $score,
            band: GeoPillarScore::bandFor($score),
            notes: $notes,
            debug: [
                'organizationNamed' => $points >= 2,
                'sameAsCount' => $sameAsCount,
                'authorPoints' => $authorCoverage['points'],
            ],
        );
    }

    /**
     * @return array{points: int, note: ?string}
     */
    private function scoreAuthorCoverage(ElementInterface $element): array
    {
        $elementId = (int) ($element->id ?? 0);
        if ($elementId <= 0) {
            return ['points' => 0, 'note' => null];
        }

        $relations = (new \yii\db\Query())
            ->select(['authorId'])
            ->from('{{%beacon_author_relations}}')
            ->where(['elementId' => $elementId])
            ->column();

        if ($relations === []) {
            return [
                'points' => 0,
                'note' => Craft::t('beacon', 'geo.pillar.entityCompleteness.attach.author.entry.author.person'),
            ];
        }

        $authorsWithSameAs = (new \yii\db\Query())
            ->from('{{%beacon_authors}}')
            ->where(['id' => $relations])
            ->andWhere(['<>', 'sameAs', '[]'])
            ->andWhere(['is not', 'sameAs', null])
            ->count();

        if ((int) $authorsWithSameAs > 0) {
            return ['points' => 3, 'note' => null];
        }

        return [
            'points' => 1,
            'note' => Craft::t('beacon', 'geo.pillar.entityCompleteness.author.attached.but.no.external'),
        ];
    }
}
